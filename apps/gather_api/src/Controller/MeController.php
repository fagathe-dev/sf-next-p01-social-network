<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\RefreshToken;
use App\Security\AuthCookieManager;
use Doctrine\ORM\EntityManagerInterface;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Repository\ConversationRepository;
use Fagathe\GatherCore\Repository\FriendshipRepository;
use Fagathe\GatherCore\Service\AccountService;
use Fagathe\GatherCore\Service\AdminHandoffService;
use Fagathe\GatherCore\Service\AttachmentRejectedException;
use Fagathe\GatherCore\Service\AttachmentStorageService;
use Fagathe\GatherCore\Service\NotificationService;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/me', name: 'api_me_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class MeController extends ApiController
{
    public function __construct(
        private readonly AccountService $account,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->respond($this->account->getProfile());
    }

    /** Compteurs du menu latéral, relus régulièrement par le front. */
    #[Route('/counters', name: 'counters', methods: ['GET'])]
    public function counters(#[CurrentUser] User $user, ConversationRepository $conversations, FriendshipRepository $friendships, NotificationService $notifications): JsonResponse
    {
        return $this->json([
            'unreadMessages' => $conversations->unreadTotal($user),
            'friendRequests' => count($friendships->pendingFor($user)),
            'notifications' => $notifications->unreadCount($user),
        ]);
    }

    #[Route('/profile', name: 'profile', methods: ['PATCH'])]
    public function profile(Request $request): JsonResponse
    {
        return $this->respond($this->account->updateProfile($request));
    }

    #[Route('/password', name: 'password', methods: ['PATCH'])]
    public function password(Request $request): JsonResponse
    {
        return $this->respond($this->account->changePassword($request));
    }

    /** Jeton de passage vers le back-office, réservé à l'équipe de modération (voir AdminHandoffService). */
    #[Route('/admin-handoff', name: 'admin_handoff', methods: ['POST'])]
    public function adminHandoff(#[CurrentUser] User $user, AdminHandoffService $handoff): JsonResponse
    {
        $token = $handoff->issue($user);
        if ($token === null) {
            return $this->json(['message' => 'Espace réservé à l\'équipe de modération.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['token' => $token, 'expiresIn' => AdminHandoffService::TTL_SECONDS]);
    }

    /** L'adresse ne change qu'après le clic sur le lien envoyé à la nouvelle adresse. */
    #[Route('/email', name: 'email', methods: ['PATCH'])]
    public function email(Request $request): JsonResponse
    {
        return $this->respond($this->account->changeEmail($request));
    }

    /** Suppression du compte, étape 1 : envoi du code de confirmation par e-mail. */
    #[Route('/delete-request', name: 'delete_request', methods: ['POST'])]
    public function deleteRequest(): JsonResponse
    {
        return $this->respond($this->account->requestAccountDeletion());
    }

    /** Suppression du compte, étape 2 : suppression définitive avec le code reçu. */
    #[Route('', name: 'delete', methods: ['DELETE'])]
    public function delete(Request $request, AuthCookieManager $cookies): JsonResponse
    {
        $response = $this->respond($this->account->deleteAccount($request));
        if ($response->getStatusCode() === Response::HTTP_NO_CONTENT) {
            $cookies->clear($response);
        }

        return $response;
    }

    /** Le compte devient invisible et toutes ses sessions sont fermées ; se reconnecter le réactive. */
    #[Route('/deactivate', name: 'deactivate', methods: ['POST'])]
    public function deactivate(#[CurrentUser] User $user, AuthCookieManager $cookies): JsonResponse
    {
        $user->deactivate();
        $this->em->flush();

        $this->em->createQueryBuilder()
            ->delete(RefreshToken::class, 't')
            ->where('t.username = :username')
            ->setParameter('username', $user->getUserIdentifier())
            ->getQuery()
            ->execute();

        $response = $this->json(null, Response::HTTP_NO_CONTENT);
        $cookies->clear($response);

        return $response;
    }

    #[Route('/privacy', name: 'privacy', methods: ['PATCH'])]
    public function privacy(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $isPrivate = is_array($data) ? ($data['isPrivate'] ?? null) : null;
        $isExcluded = is_array($data) ? ($data['isExcludedFromSearch'] ?? null) : null;
        if ((!is_bool($isPrivate) && !is_bool($isExcluded)) || ($isPrivate !== null && !is_bool($isPrivate)) || ($isExcluded !== null && !is_bool($isExcluded))) {
            return $this->json(['message' => 'Format de données invalide.'], Response::HTTP_BAD_REQUEST);
        }

        if ($isPrivate !== null) {
            $user->setIsPrivate($isPrivate);
        }
        if ($isExcluded !== null) {
            $user->setIsExcludedFromSearch($isExcluded);
        }
        $this->em->flush();

        return $this->json(['isPrivate' => $user->isPrivate(), 'isExcludedFromSearch' => $user->isExcludedFromSearch()]);
    }

    #[Route('/avatar', name: 'avatar', methods: ['POST'])]
    public function avatar(Request $request, #[CurrentUser] User $user, AttachmentStorageService $storage): JsonResponse
    {
        return $this->storeImage($request, $storage, 'avatars', $user->getAvatar(), static fn(string $url) => $user->setAvatar($url));
    }

    #[Route('/banner', name: 'banner', methods: ['POST'])]
    public function banner(Request $request, #[CurrentUser] User $user, AttachmentStorageService $storage): JsonResponse
    {
        return $this->storeImage($request, $storage, 'banners', $user->getBanner(), static fn(string $url) => $user->setBanner($url));
    }

    #[Route('/sessions', name: 'sessions', methods: ['GET'])]
    public function sessions(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $current = $request->cookies->get(AuthCookieManager::REFRESH_COOKIE);

        return $this->json(['items' => array_map(
            static fn(RefreshToken $token) => [
                'id' => $token->getId(),
                'userAgent' => $token->getUserAgent(),
                'createdAt' => $token->getCreatedAt(),
                'expiresAt' => $token->getValid(),
                'isCurrent' => $token->getRefreshToken() === $current,
            ],
            $this->em->createQueryBuilder()
                ->select('t')
                ->from(RefreshToken::class, 't')
                ->where('t.username = :username AND t.valid > :now')
                ->setParameter('username', $user->getUserIdentifier())
                ->setParameter('now', new \DateTime())
                ->orderBy('t.id', 'DESC')
                ->getQuery()
                ->getResult()
        )]);
    }

    /** Effet au plus tard à l'expiration du JWT en cours (15 min). */
    #[Route('/sessions/{id}', name: 'session_revoke', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function revokeSession(int $id, #[CurrentUser] User $user, RefreshTokenManagerInterface $tokens): JsonResponse
    {
        $token = $this->em->getRepository(RefreshToken::class)->find($id);
        if ($token === null || $token->getUsername() !== $user->getUserIdentifier()) {
            return $this->json(['message' => 'Session introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $tokens->delete($token);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @param callable(string): mixed $assign
     */
    private function storeImage(Request $request, AttachmentStorageService $storage, string $directory, ?string $previous, callable $assign): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['message' => 'Aucun fichier reçu.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $url = $storage->storePublicImage($file, $directory);
        } catch (AttachmentRejectedException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $assign($url);
        $this->em->flush();

        // L'ancienne image resterait sinon accessible à son adresse publique
        $previousFile = $storage->publicImageFile($previous);
        if ($previousFile !== null) {
            $storage->deleteFiles([$previousFile]);
        }

        return $this->json(['url' => $url]);
    }
}
