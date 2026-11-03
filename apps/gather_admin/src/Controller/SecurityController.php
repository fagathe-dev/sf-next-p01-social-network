<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $utils): Response
    {
        if ($this->isGranted('ROLE_MODERATOR')) {
            return $this->redirectToRoute('admin_reports');
        }

        return $this->render('security/login.html.twig', [
            'lastUsername' => $utils->getLastUsername(),
            'error' => $utils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'admin_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Cette route est interceptée par le pare-feu.');
    }

    #[Route('/', name: 'admin_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->redirectToRoute('admin_reports');
    }
}
