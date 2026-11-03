<?php

declare(strict_types=1);

namespace App\Controller;

use Fagathe\GatherCore\Service\PostService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api', name: 'api_')]
final class PostController extends ApiController
{
    public function __construct(
        private readonly PostService $service,
    ) {}

    #[Route('/posts', name: 'posts_feed', methods: ['GET'])]
    public function feed(#[MapQueryParameter] string $feed = 'recents', #[MapQueryParameter] int $page = 1): JsonResponse
    {
        return $this->respond($this->service->feed($feed, $page));
    }

    #[Route('/posts', name: 'posts_create', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function create(Request $request): JsonResponse
    {
        return $this->respond($this->service->create($request));
    }

    #[Route('/posts/{slugAndId}', name: 'posts_show', methods: ['GET'], requirements: ['slugAndId' => '.*[0-9a-fA-F-]{36}'])]
    public function show(string $slugAndId): JsonResponse
    {
        return $this->respond($this->service->show($slugAndId));
    }

    #[Route('/posts/{id}', name: 'posts_update', methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function update(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->service->update($id, $request));
    }

    #[Route('/posts/{id}/attachments', name: 'posts_attachments', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function addAttachments(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->service->addAttachments($id, $request));
    }

    #[Route('/posts/{id}/repost', name: 'posts_repost', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function repost(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->service->repost($id, $request));
    }

    #[Route('/posts/{id}/repost', name: 'posts_unrepost', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function unrepost(string $id): JsonResponse
    {
        return $this->respond($this->service->unrepost($id));
    }

    #[Route('/comments/{id}', name: 'comments_update', methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function updateComment(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->service->updateComment($id, $request));
    }

    #[Route('/comments/{id}/like', name: 'comments_like', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function likeComment(string $id): JsonResponse
    {
        return $this->respond($this->service->toggleCommentLike($id));
    }

    #[Route('/posts/{id}', name: 'posts_delete', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function delete(string $id): JsonResponse
    {
        return $this->respond($this->service->delete($id));
    }

    /** Consultation de la page dédiée de la publication, la seule à compter une vue. */
    #[Route('/posts/{id}/view', name: 'posts_view', methods: ['POST'])]
    public function view(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->service->registerView($id, $request));
    }

    #[Route('/posts/{id}/like', name: 'posts_like', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function like(string $id): JsonResponse
    {
        return $this->respond($this->service->toggleLike($id));
    }

    #[Route('/posts/{id}/bookmark', name: 'posts_bookmark', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function bookmark(string $id): JsonResponse
    {
        return $this->respond($this->service->toggleBookmark($id));
    }

    #[Route('/posts/{id}/bookmark', name: 'posts_bookmark_update', methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function updateBookmark(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->service->updateBookmark($id, $request));
    }

    #[Route('/bookmarks', name: 'bookmarks', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function bookmarks(#[MapQueryParameter] int $page = 1, #[MapQueryParameter] ?string $collection = null): JsonResponse
    {
        return $this->respond($this->service->listBookmarks($page, $collection));
    }

    #[Route('/users/{username}/collections/{collection}', name: 'public_collection', methods: ['GET'])]
    public function publicCollection(string $username, string $collection, #[MapQueryParameter] int $page = 1): JsonResponse
    {
        return $this->respond($this->service->publicCollection($username, $collection, $page));
    }

    #[Route('/posts/{id}/comments', name: 'comments_list', methods: ['GET'])]
    public function comments(string $id, #[MapQueryParameter] int $page = 1): JsonResponse
    {
        return $this->respond($this->service->listComments($id, $page));
    }

    #[Route('/posts/{id}/comments', name: 'comments_add', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function addComment(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->service->addComment($id, $request));
    }

    #[Route('/comments/{id}', name: 'comments_delete', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function deleteComment(string $id): JsonResponse
    {
        return $this->respond($this->service->deleteComment($id));
    }

    /** Fichiers des posts privés : contrôle d'accès + HTTP 206 pour le streaming. */
    #[Route('/media/{id}', name: 'media', methods: ['GET'])]
    public function media(string $id, #[MapQueryParameter] bool $thumbnail = false): Response
    {
        return $this->service->media($id, $thumbnail);
    }
}
