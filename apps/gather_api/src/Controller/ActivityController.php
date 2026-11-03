<?php

declare(strict_types=1);

namespace App\Controller;

use Fagathe\GatherCore\Service\ActivityService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Onglet Activité : uniquement le membre de la session, aucun paramètre de membre accepté. */
#[Route('/api/me/activity', name: 'api_activity_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ActivityController extends ApiController
{
    public function __construct(
        private readonly ActivityService $service,
    ) {}

    #[Route('/likes', name: 'likes', methods: ['GET'])]
    public function likes(#[MapQueryParameter] int $page = 1): JsonResponse
    {
        return $this->respond($this->service->likes($page));
    }

    #[Route('/bookmarks', name: 'bookmarks', methods: ['GET'])]
    public function bookmarks(#[MapQueryParameter] int $page = 1): JsonResponse
    {
        return $this->respond($this->service->bookmarks($page));
    }

    #[Route('/comments', name: 'comments', methods: ['GET'])]
    public function comments(#[MapQueryParameter] int $page = 1): JsonResponse
    {
        return $this->respond($this->service->comments($page));
    }

    #[Route('/reposts', name: 'reposts', methods: ['GET'])]
    public function reposts(#[MapQueryParameter] int $page = 1): JsonResponse
    {
        return $this->respond($this->service->reposts($page));
    }

    #[Route('/views', name: 'views', methods: ['GET'])]
    public function views(#[MapQueryParameter] int $page = 1): JsonResponse
    {
        return $this->respond($this->service->views($page));
    }
}
