<?php

declare(strict_types=1);

namespace App\Controller;

use Fagathe\GatherCore\Service\SupportService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/support/tickets', name: 'api_support_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class SupportController extends ApiController
{
    public function __construct(
        private readonly SupportService $service,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->respond($this->service->listMine());
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        return $this->respond($this->service->create($request));
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return $this->respond($this->service->show($id));
    }

    #[Route('/{id}/messages', name: 'reply', methods: ['POST'])]
    public function reply(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->service->reply($id, $request));
    }

    #[Route('/{id}/messages/{messageId}/attachments/{index}', name: 'attachment', methods: ['GET'], requirements: ['index' => '\d+'])]
    public function attachment(string $id, string $messageId, int $index): Response
    {
        return $this->service->attachment($id, $messageId, $index);
    }
}
