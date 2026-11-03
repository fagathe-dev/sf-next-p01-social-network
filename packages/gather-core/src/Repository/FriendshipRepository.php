<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\GatherCore\Entity\Friendship;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Social\FriendshipStatus;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Friendship>
 */
class FriendshipRepository extends ServiceEntityRepository
{
    private const VISIBLE_FRIENDS_SQL = "SELECT %s FROM friendship f
        INNER JOIN users u ON u.id = IF(f.requester_id = :user, f.addressee_id, f.requester_id)
        WHERE (f.requester_id = :user OR f.addressee_id = :user) AND f.status = 'accepted'
          AND u.status != 'banned' AND u.deactivated_at IS NULL";

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Friendship::class);
    }

    public function findBetween(User $a, User $b): ?Friendship
    {
        return $this->createQueryBuilder('f')
            ->where('(f.requester = :a AND f.addressee = :b) OR (f.requester = :b AND f.addressee = :a)')
            ->setParameter('a', $a->getId(), UuidType::NAME)
            ->setParameter('b', $b->getId(), UuidType::NAME)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Uuid> */
    public function friendIds(User $user): array
    {
        return array_map(
            static fn(Friendship $friendship) => $friendship->otherThan($user)->getId(),
            $this->accepted($user)
        );
    }

    /** @return list<Friendship> */
    public function accepted(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->addSelect('requester', 'addressee')
            ->innerJoin('f.requester', 'requester')
            ->innerJoin('f.addressee', 'addressee')
            ->where('(f.requester = :user OR f.addressee = :user) AND f.status = :status')
            ->andWhere('requester.deactivatedAt IS NULL AND addressee.deactivatedAt IS NULL')
            ->setParameter('user', $user->getId(), UuidType::NAME)
            ->setParameter('status', FriendshipStatus::Accepted)
            ->getQuery()
            ->getResult();
    }

    /**
     * Amis visibles d'un membre, y compris ceux dont le compte est privé.
     *
     * @return array{ids: list<string>, hasMore: bool} Identifiants binaires
     */
    public function friendIdsPage(User $user, int $page, int $perPage): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            sprintf(self::VISIBLE_FRIENDS_SQL, 'u.id') . sprintf(' ORDER BY u.username ASC LIMIT %d OFFSET %d', $perPage + 1, (max(1, $page) - 1) * $perPage),
            ['user' => $user->getId()->toBinary()]
        );

        return ['ids' => array_slice($ids, 0, $perPage), 'hasMore' => count($ids) > $perPage];
    }

    public function countVisibleFriends(User $user): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            sprintf(self::VISIBLE_FRIENDS_SQL, 'COUNT(*)'),
            ['user' => $user->getId()->toBinary()]
        );
    }

    /** @return list<Friendship> */
    public function pendingFor(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->addSelect('requester')
            ->innerJoin('f.requester', 'requester')
            ->where('f.addressee = :user AND f.status = :status')
            ->setParameter('user', $user->getId(), UuidType::NAME)
            ->setParameter('status', FriendshipStatus::Pending)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
