<?php

declare(strict_types=1);

namespace App\Controller;

use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Service\EmailPreviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Throwable;

#[Route('/emails/templates', name: 'admin_emails_')]
final class EmailTemplateController extends AbstractController
{
    public function __construct(
        private readonly EmailPreviewService $previews,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->render('emails/list.html.twig', ['templates' => $this->previews->listPreviews()->data]);
    }

    /** Page d'aperçu : l'e-mail est chargé dans une iframe, à largeur desktop ou mobile. */
    #[Route('/{templateName}/preview', name: 'preview', methods: ['GET'])]
    public function preview(string $templateName): Response
    {
        if ($this->previews->renderPreview($templateName) === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('emails/preview.html.twig', ['templateName' => $templateName]);
    }

    /** HTML brut de l'e-mail : sandboxé car il s'affiche dans le domaine du back-office. */
    #[Route('/{templateName}/render', name: 'render', methods: ['GET'])]
    public function render_(string $templateName): Response
    {
        $html = $this->previews->renderPreview($templateName);
        if ($html === null) {
            throw $this->createNotFoundException();
        }

        return new Response($html, headers: ['Content-Security-Policy' => 'sandbox; default-src \'none\'; img-src * data:; style-src \'unsafe-inline\'']);
    }

    #[Route('/{templateName}/test', name: 'test', methods: ['POST'])]
    public function test(string $templateName, Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('email_test', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        try {
            $sent = $this->previews->sendTest($templateName, (string) $user->getEmail());
            if (!$sent) {
                throw $this->createNotFoundException();
            }
            $this->addFlash('success', sprintf('E-mail de test envoyé à %s.', $user->getEmail()));
        } catch (Throwable $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }
            $this->addFlash('error', 'Échec de l\'envoi : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_emails_preview', ['templateName' => $templateName]);
    }
}
