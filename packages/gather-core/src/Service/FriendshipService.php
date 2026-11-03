<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Fagathe\CorePhp\Http\ResponseTrait;
use Fagathe\GatherCore\Dto\Output\UserSummaryOutput;
use Fagathe\GatherCore\Entity\Friendship;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Social\FriendshipStatus;
use Fagathe\GatherCore\Enum\Social\NotificationType;
use Fagathe\GatherCore\Repository\FriendshipRepository;
use Fagathe\GatherCore\Repository\PostRepository;
use Fagathe\GatherCore\Repository\UserRepository;
use Fagathe\GatherCore\Security\Audience;
use Fagathe\GatherCore\Security\AudienceResolver;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class FriendshipService
{
    use ResponseTrait;

    private const FRIENDS_PER_PAGE = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FriendshipRepository $friendships,
        private readonly UserRepository $users,
        private readonly PostRepository $posts,
        private readonly AudienceResolver $audiences,
        private readonly PostService $postService,
        private readonly NotificationService $notifier,
    ) {}

    public function overview(): object
    {
        $me = $this->audiences->current()->user;

        return $this->sendJson([
            'friends' => array_map(
                static fn(Friendship $f) => UserSummaryOutput::from($f->otherThan($me)),
                $this->friendships->accepted($me)
            ),
            'requests' => array_map(
                static fn(Friendship $f) => UserSummaryOutput::from($f->getRequester()),
                $this->friendships->pendingFor($me)
            ),
        ]);
    }

    public function request(string $username): object
    {
        $audience = $this->audiences->current();
        $target = $this->findUser($username);
        if ($target === null || $audience->isSelf($target)) {
            return $this->notFound();
        }
        if (!$audience->canWrite()) {
            return $this->sendJson(['message' => 'Votre compte ne peut pas envoyer de demande pour le moment.'], Response::HTTP_FORBIDDEN);
        }

        $existing = $this->friendships->findBetween($audience->user, $target);
        $event = null;
        if ($existing === null) {
            $existing = new Friendship($audience->user, $target);
            $this->em->persist($existing);
            $event = NotificationType::FriendRequestReceived;
        } elseif ($existing->getStatus() === FriendshipStatus::Pending && $existing->getAddressee()->getId()->equals($audience->user->getId())) {
            // L'autre personne avait déjà fait la demande : les deux le souhaitent
            $existing->setStatus(FriendshipStatus::Accepted);
            $event = NotificationType::FriendRequestAccepted;
        } elseif ($existing->getStatus() === FriendshipStatus::Blocked) {
            return $this->notFound();
        }
        $this->em->flush();
        if ($event !== null) {
            $this->notifier->notify($target, $audience->user, $event, $audience->user);
        }

        return $this->sendJson(['status' => $this->relationFor($audience->user, $target)]);
    }

    public function accept(string $username): object
    {
        $me = $this->audiences->current()->user;
        $friendship = $this->incomingRequest($me, $username);
        if ($friendship === null) {
            return $this->notFound();
        }

        $friendship->setStatus(FriendshipStatus::Accepted);
        $this->em->flush();
        $this->notifier->notify($friendship->getRequester(), $me, NotificationType::FriendRequestAccepted, $me);

        return $this->sendJson(['status' => 'friends']);
    }

    /** Refuse une demande reçue, annule une demande envoyée ou retire un ami. */
    public function remove(string $username): object
    {
        $me = $this->audiences->current()->user;
        $target = $this->findUser($username);
        $friendship = $target !== null ? $this->friendships->findBetween($me, $target) : null;
        if ($friendship === null || $friendship->getStatus() === FriendshipStatus::Blocked) {
            return $this->notFound();
        }

        $this->em->remove($friendship);
        $this->em->flush();

        return $this->sendJson(['status' => 'none']);
    }

    /** Membres publics à découvrir : ni soi-même, ni comptes privés ou exclus de la recherche, ni relations existantes. */
    public function suggestions(int $limit = 5): object
    {
        $audience = $this->audiences->current();
        if ($audience->user === null) {
            return $this->sendJson(['items' => []]);
        }

        $connection = $this->em->getConnection();
        $excluded = $connection->fetchFirstColumn(
            'SELECT addressee_id FROM friendship WHERE requester_id = :me UNION SELECT requester_id FROM friendship WHERE addressee_id = :me',
            ['me' => $audience->user->getId()->toBinary()]
        );
        $excluded[] = $audience->user->getId()->toBinary();

        $ids = $connection->fetchFirstColumn(
            "SELECT id FROM users WHERE is_private = 0 AND is_excluded_from_search = 0 AND status = 'active' AND is_verified = 1 AND deactivated_at IS NULL AND id NOT IN (:excluded) ORDER BY created_at DESC LIMIT " . $limit,
            ['excluded' => $excluded],
            ['excluded' => ArrayParameterType::BINARY]
        );

        return $this->sendJson([
            'items' => array_values(array_filter(array_map(function (string $id) {
                $user = $this->users->find(Uuid::fromBinary($id));

                return $user !== null ? UserSummaryOutput::from($user) : null;
            }, $ids))),
        ]);
    }

    /**
     * Profil d'un membre : le compte privé ne dévoile que son existence à qui n'est pas son ami.
     */
    public function profile(string $username): object
    {
        $audience = $this->audiences->current();
        $user = $this->findUser($username);
        if ($user === null) {
            return $this->notFound();
        }

        $canSeeDetails = $this->canSeeDetails($audience, $user);

        return $this->sendJson([
            'user' => UserSummaryOutput::from($user),
            'isPrivate' => $user->isPrivate(),
            'isExcludedFromSearch' => $user->isExcludedFromSearch(),
            'canSeeDetails' => $canSeeDetails,
            'friendsCount' => $canSeeDetails ? $this->friendships->countVisibleFriends($user) : null,
            'bio' => $canSeeDetails ? $user->getBio() : null,
            'banner' => $canSeeDetails ? $user->getBanner() : null,
            'createdAt' => $canSeeDetails ? $user->getCreatedAt() : null,
            'relation' => $audience->user !== null ? $this->relationFor($audience->user, $user) : 'none',
        ]);
    }

    public function userPosts(string $username, int $page): object
    {
        $audience = $this->audiences->current();
        $user = $this->findUser($username);
        if ($user === null) {
            return $this->notFound();
        }

        // Profil restreint : pas de contenus, même ceux publiés dans une communauté publique
        if (!$this->canSeeDetails($audience, $user)) {
            return $this->sendJson(['items' => [], 'page' => 1, 'hasMore' => false]);
        }

        return $this->sendJson($this->postService->page($this->posts->byAuthor($user, $audience, $page), $audience));
    }

    /** Amis d'un membre, comptes privés compris : réservé à qui peut voir le détail de son profil. */
    public function friendsOf(string $username, int $page): object
    {
        $audience = $this->audiences->current();
        $user = $this->findUser($username);
        if ($user === null) {
            return $this->notFound();
        }
        if (!$this->canSeeDetails($audience, $user)) {
            return $this->sendJson(['message' => 'Ce compte est privé.'], Response::HTTP_FORBIDDEN);
        }

        $result = $this->friendships->friendIdsPage($user, $page, self::FRIENDS_PER_PAGE);

        return $this->sendJson([
            'items' => array_values(array_filter(array_map(function (string $id) {
                $friend = $this->users->find(Uuid::fromBinary($id));

                return $friend !== null ? UserSummaryOutput::from($friend) : null;
            }, $result['ids']))),
            'total' => $this->friendships->countVisibleFriends($user),
            'page' => max(1, $page),
            'hasMore' => $result['hasMore'],
        ]);
    }

    private function canSeeDetails(Audience $audience, User $user): bool
    {
        return !$user->isPrivate() || $audience->isSelf($user) || $audience->isFriendOf($user) || $audience->isStaff();
    }

    private function relationFor(User $me, User $other): string
    {
        if ($me->getId()->equals($other->getId())) {
            return 'self';
        }

        $friendship = $this->friendships->findBetween($me, $other);

        return match (true) {
            $friendship === null => 'none',
            $friendship->getStatus() === FriendshipStatus::Accepted => 'friends',
            $friendship->getStatus() === FriendshipStatus::Blocked => 'blocked',
            $friendship->getRequester()->getId()->equals($me->getId()) => 'request_sent',
            default => 'request_received',
        };
    }

    private function incomingRequest(User $me, string $username): ?Friendship
    {
        $requester = $this->findUser($username);
        $friendship = $requester !== null ? $this->friendships->findBetween($me, $requester) : null;
        if ($friendship === null || $friendship->getStatus() !== FriendshipStatus::Pending) {
            return null;
        }

        return $friendship->getAddressee()->getId()->equals($me->getId()) ? $friendship : null;
    }

    private function findUser(string $username): ?User
    {
        $user = $this->users->findOneBy(['username' => $username]);

        return $user !== null && $user->isVisible() ? $user : null;
    }

    private function notFound(): object
    {
        return $this->sendJson(['message' => 'Membre introuvable.'], Response::HTTP_NOT_FOUND);
    }
}
