<?php

declare(strict_types=1);

namespace App\Controller;

use Fagathe\GatherCore\Service\FriendshipService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_')]
final class UserController extends ApiController
{
    public function __construct(
        private readonly FriendshipService $service,
    ) {}

    #[Route('/users/{username}', name: 'users_show', methods: ['GET'])]
    public function show(string $username): JsonResponse
    {
        return $this->respond($this->service->profile($username));
    }

    #[Route('/users/{username}/posts', name: 'users_posts', methods: ['GET'])]
    public function posts(string $username, #[MapQueryParameter] int $page = 1): JsonResponse
    {
        return $this->respond($this->service->userPosts($username, $page));
    }

    #[Route('/users/{username}/friends', name: 'users_friends', methods: ['GET'])]
    public function userFriends(string $username, #[MapQueryParameter] int $page = 1): JsonResponse
    {
        return $this->respond($this->service->friendsOf($username, $page));
    }

    #[Route('/friends', name: 'friends', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function friends(): JsonResponse
    {
        return $this->respond($this->service->overview());
    }

    #[Route('/friends/suggestions', name: 'friends_suggestions', methods: ['GET'])]
    public function suggestions(): JsonResponse
    {
        return $this->respond($this->service->suggestions());
    }

    #[Route('/friends/{username}', name: 'friends_request', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function request(string $username): JsonResponse
    {
        return $this->respond($this->service->request($username));
    }

    #[Route('/friends/{username}/accept', name: 'friends_accept', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function accept(string $username): JsonResponse
    {
        return $this->respond($this->service->accept($username));
    }

    #[Route('/friends/{username}', name: 'friends_remove', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function remove(string $username): JsonResponse
    {
        return $this->respond($this->service->remove($username));
    }
}
