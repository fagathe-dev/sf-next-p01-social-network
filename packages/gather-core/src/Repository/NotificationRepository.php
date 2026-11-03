<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\GatherCore\Entity\Notification;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Social\NotificationType;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 20;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function findUnreadFor(User $recipient, NotificationType $type, string $targetType, string $targetId): ?Notification
    {
        return $this->createQueryBuilder('n')
            ->where('n.recipient = :recipient AND n.type = :type AND n.targetType = :targetType AND n.targetId = :targetId AND n.readAt IS NULL')
            ->setParameter('recipient', $recipient->getId(), UuidType::NAME)
            ->setParameter('type', $type)
            ->setParameter('targetType', $targetType)
            ->setParameter('targetId', $targetId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array{items: list<Notification>, page: int, hasMore: bool}
     */
    public function listFor(User $recipient, bool $unreadOnly, int $page, int $perPage = self::PER_PAGE): array
    {
        $page = max(1, $page);
        $qb = $this->createQueryBuilder('n')
            ->addSelect('actor')
            ->leftJoin('n.lastActor', 'actor')
            ->where('n.recipient = :recipient')
            ->setParameter('recipient', $recipient->getId(), UuidType::NAME)
            ->orderBy('n.updatedAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage + 1);

        if ($unreadOnly) {
            $qb->andWhere('n.readAt IS NULL');
        }

        $items = $qb->getQuery()->getResult();

        return ['items' => array_slice($items, 0, $perPage), 'page' => $page, 'hasMore' => count($items) > $perPage];
    }

    public function countFor(User $recipient, bool $unreadOnly): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.recipient = :recipient')
            ->setParameter('recipient', $recipient->getId(), UuidType::NAME);

        if ($unreadOnly) {
            $qb->andWhere('n.readAt IS NULL');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function markAllRead(User $recipient): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.readAt', ':now')
            ->where('n.recipient = :recipient AND n.readAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('recipient', $recipient->getId(), UuidType::NAME)
            ->getQuery()
            ->execute();
    }
}
