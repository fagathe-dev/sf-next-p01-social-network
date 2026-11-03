<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Fagathe\GatherCore\Entity\SupportMessage;
use Fagathe\GatherCore\Entity\SupportTicket;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Support\TicketCategory;
use Fagathe\GatherCore\Enum\Support\TicketPriority;
use Fagathe\GatherCore\Enum\Support\TicketStatus;
use Fagathe\GatherCore\Repository\SupportTicketRepository;
use Fagathe\GatherCore\Service\SupportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

#[Route('/support/tickets', name: 'admin_support_')]
final class SupportDeskController extends AbstractController
{
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request, SupportTicketRepository $tickets): Response
    {
        $status = TicketStatus::tryFrom($request->query->getString('status'));
        $priority = TicketPriority::tryFrom($request->query->getString('priority'));
        $category = TicketCategory::tryFrom($request->query->getString('category'));

        return $this->render('support/list.html.twig', [
            'tickets' => $tickets->desk($status, $priority, $category),
            'filters' => ['status' => $status, 'priority' => $priority, 'category' => $category],
            'statuses' => TicketStatus::cases(),
            'priorities' => TicketPriority::cases(),
            'categories' => TicketCategory::cases(),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id, EntityManagerInterface $em, SupportService $support): Response
    {
        $ticket = $this->findTicket($id, $em);

        return $this->render('support/show.html.twig', [
            'ticket' => $ticket,
            'messages' => $support->thread($ticket, includeNotes: true),
            'statuses' => TicketStatus::cases(),
            'priorities' => TicketPriority::cases(),
        ]);
    }

    #[Route('/{id}/messages/{messageId}/attachments/{index}', name: 'attachment', methods: ['GET'], requirements: ['index' => '\d+'])]
    public function attachment(
        string $id,
        string $messageId,
        int $index,
        EntityManagerInterface $em,
        SupportService $support,
        #[Autowire('%kernel.project_dir%/../gather_api/var/private_media')]
        string $privateRoot,
    ): Response {
        $ticket = $this->findTicket($id, $em);
        $message = Uuid::isValid($messageId) ? $em->find(SupportMessage::class, Uuid::fromString($messageId)) : null;
        if ($message === null || !$message->getTicket()->getId()->equals($ticket->getId())) {
            throw $this->createNotFoundException();
        }

        return $support->attachmentResponse($message, $index, static fn(string $path) => $privateRoot . '/' . $path);
    }

    #[Route('/{id}/reply', name: 'reply', methods: ['POST'])]
    public function reply(string $id, Request $request, EntityManagerInterface $em, SupportService $support, #[CurrentUser] User $staff): Response
    {
        if (!$this->isCsrfTokenValid('ticket' . $id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $ticket = $this->findTicket($id, $em);
        $content = trim($request->request->getString('content'));

        $priority = TicketPriority::tryFrom($request->request->getString('priority'));
        if ($priority !== null) {
            $ticket->setPriority($priority);
        }

        if ($content === '') {
            $this->addFlash('error', 'Le message est vide.');
            $em->flush();
        } else {
            $support->staffReply(
                $ticket,
                $staff,
                $content,
                $request->request->getBoolean('isInternalNote'),
                TicketStatus::tryFrom($request->request->getString('status')),
            );
            $this->addFlash('success', 'Message enregistré.');
        }

        return $this->redirectToRoute('admin_support_show', ['id' => $id]);
    }

    private function findTicket(string $id, EntityManagerInterface $em): SupportTicket
    {
        $ticket = Uuid::isValid($id) ? $em->find(SupportTicket::class, Uuid::fromString($id)) : null;

        return $ticket ?? throw $this->createNotFoundException();
    }
}
