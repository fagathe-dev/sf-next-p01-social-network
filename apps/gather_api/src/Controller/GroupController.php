<?php

declare(strict_types=1);

namespace App\Controller;

use Fagathe\GatherCore\Service\GroupService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/groups', name: 'api_groups_')]
final class GroupController extends ApiController
{
    public function __construct(
        private readonly GroupService $service,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(#[MapQueryParameter] int $page = 1, #[MapQueryParameter] bool $mine = false): JsonResponse
    {
        return $this->respond($this->service->list($page, $mine));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function create(Request $request): JsonResponse
    {
        return $this->respond($this->service->create($request));
    }

    #[Route('/{slug}', name: 'show', methods: ['GET'])]
    public function show(string $slug): JsonResponse
    {
        return $this->respond($this->service->show($slug));
    }

    #[Route('/{slug}/posts', name: 'posts', methods: ['GET'])]
    public function posts(string $slug, #[MapQueryParameter] int $page = 1): JsonResponse
    {
        return $this->respond($this->service->posts($slug, $page));
    }

    #[Route('/{slug}/members', name: 'members', methods: ['GET'])]
    public function members(string $slug): JsonResponse
    {
        return $this->respond($this->service->members($slug));
    }

    #[Route('/{slug}/join', name: 'join', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function join(string $slug): JsonResponse
    {
        return $this->respond($this->service->join($slug));
    }

    #[Route('/{slug}/leave', name: 'leave', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function leave(string $slug): JsonResponse
    {
        return $this->respond($this->service->leave($slug));
    }

    #[Route('/{slug}/invite', name: 'invite', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function invite(string $slug, Request $request): JsonResponse
    {
        return $this->respond($this->service->invite($slug, $request));
    }

    #[Route('/{slug}/members/{username}/approve', name: 'member_approve', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function approve(string $slug, string $username): JsonResponse
    {
        return $this->respond($this->service->decide($slug, $username, true));
    }

    #[Route('/{slug}/members/{username}/reject', name: 'member_reject', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function reject(string $slug, string $username): JsonResponse
    {
        return $this->respond($this->service->decide($slug, $username, false));
    }
}
