<?php

declare(strict_types=1);

namespace App\Controller;

use Fagathe\GatherCore\Generator\AuthLinkGenerator;
use Fagathe\GatherCore\Security\UserChecker;
use Fagathe\GatherCore\Service\AdminHandoffService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccountStatusException;

/**
 * Connexion par l'application : le front, où le membre est déjà connecté, renvoie ici avec un jeton à usage unique.
 */
final class SsoController extends AbstractController
{
    /** Point d'entrée depuis la page de connexion : le front vérifie la session puis revient avec un jeton. */
    #[Route('/sso/start', name: 'admin_sso_start', methods: ['GET'])]
    public function start(Request $request, AuthLinkGenerator $links): RedirectResponse
    {
        return $this->redirect($links->adminSsoUrl(self::safeTarget($request->query->getString('target'))));
    }

    #[Route('/sso', name: 'admin_sso', methods: ['GET'])]
    public function redeem(Request $request, AdminHandoffService $handoff, UserChecker $checker, Security $security): Response
    {
        $user = $handoff->redeem($request->query->getString('token'));

        if ($user !== null) {
            try {
                $checker->checkPreAuth($user);
                $checker->checkPostAuth($user);
            } catch (AccountStatusException) {
                $user = null;
            }
        }

        if ($user === null) {
            $this->addFlash('danger', 'Ce lien de connexion est invalide ou a expiré. Connectez-vous ou réessayez depuis l\'application.');
            $response = $this->redirectToRoute('admin_login');
        } else {
            $security->login($user, 'form_login', 'main');
            $target = self::safeTarget($request->query->getString('target'));
            $response = $target !== '' ? $this->redirect($target) : $this->redirectToRoute('admin_reports');
        }

        // Le jeton figure dans l'URL : il ne doit fuiter ni par le Referer ni par un cache
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** Uniquement un chemin du back-office : un paramètre ne doit pas pouvoir renvoyer vers un autre site. */
    private static function safeTarget(string $target): string
    {
        return str_starts_with($target, '/') && !str_starts_with($target, '//') && !str_contains($target, '\\') && !str_starts_with($target, '/sso') ? $target : '';
    }
}
