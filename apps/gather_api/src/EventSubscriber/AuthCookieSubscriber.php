<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\AuthCookieManager;
use Doctrine\ORM\EntityManagerInterface;
use Fagathe\GatherCore\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Toute route d'authentification de gather-core qui renvoie un JWT (connexion, vérification d'e-mail…) :
 *  - le JWT reste dans la réponse : le front le stocke et l'envoie en Authorization sur ses requêtes axios ;
 *  - il est aussi posé en cookie, seul moyen de l'avoir au rendu SSR et sur les médias privés (<img>, <video>) ;
 *  - un refresh token est émis, uniquement en cookie HttpOnly ;
 *  - un compte désactivé redevient visible.
 */
final class AuthCookieSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AuthCookieManager $cookies,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/auth/') || !$response instanceof JsonResponse) {
            return;
        }

        $data = json_decode((string) $response->getContent(), true);
        if (!is_array($data) || !is_string($data['token'] ?? null) || !$response->isSuccessful()) {
            return;
        }

        $payload = $this->jwtManager->parse($data['token']);
        $user = $this->users->findByEmailOrUsername((string) ($payload['username'] ?? ''));
        if ($user === null) {
            return;
        }

        // Se reconnecter réactive un compte désactivé par son propriétaire
        if ($user->isDeactivated()) {
            $user->reactivate();
            $this->em->flush();
        }

        $this->cookies->issue($response, $request, $data['token'], $user);
    }
}
