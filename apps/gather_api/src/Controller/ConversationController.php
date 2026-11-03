<?php

declare(strict_types=1);

namespace App\Controller;

use Fagathe\GatherCore\Service\MessagingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/conversations', name: 'api_conversations_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ConversationController extends ApiController
{
    public function __construct(
        private readonly MessagingService $service,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->respond($this->service->list());
    }

    #[Route('', name: 'open', methods: ['POST'])]
    public function open(Request $request): JsonResponse
    {
        return $this->respond($this->service->open($request));
    }

    #[Route('/{id}/attachments', name: 'attachments', methods: ['GET'])]
    public function attachments(string $id): JsonResponse
    {
        return $this->respond($this->service->attachments($id));
    }

    #[Route('/{id}/search', name: 'search', methods: ['GET'])]
    public function search(string $id, #[MapQueryParameter] string $q = ''): JsonResponse
    {
        return $this->respond($this->service->search($id, $q));
    }

    #[Route('/{id}/messages', name: 'history', methods: ['GET'])]
    public function history(string $id, #[MapQueryParameter] int $page = 1): JsonResponse
    {
        return $this->respond($this->service->history($id, $page));
    }

    #[Route('/{id}/messages', name: 'send', methods: ['POST'])]
    public function send(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->service->send($id, $request));
    }

    #[Route('/{id}/messages/{messageId}/attachment', name: 'attachment', methods: ['GET'])]
    public function attachment(string $id, string $messageId): Response
    {
        return $this->service->attachment($id, $messageId);
    }
}
