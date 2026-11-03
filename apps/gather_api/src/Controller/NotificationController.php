<?php

declare(strict_types=1);

namespace App\Controller;

use Fagathe\GatherCore\Service\NotificationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/notifications', name: 'api_notifications_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class NotificationController extends ApiController
{
    public function __construct(
        private readonly NotificationService $service,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(#[MapQueryParameter] string $filter = 'all', #[MapQueryParameter] int $page = 1): JsonResponse
    {
        return $this->respond($this->service->list($filter, $page));
    }

    /** Cloche de l'en-tête : les dernières notifications. */
    #[Route('/preview', name: 'preview', methods: ['GET'])]
    public function preview(): JsonResponse
    {
        return $this->respond($this->service->preview());
    }

    #[Route('/read-all', name: 'read_all', methods: ['POST'])]
    public function readAll(): JsonResponse
    {
        return $this->respond($this->service->markAllRead());
    }

    #[Route('/{id}/read', name: 'read', methods: ['POST'])]
    public function read(string $id): JsonResponse
    {
        return $this->respond($this->service->markRead($id));
    }
}
