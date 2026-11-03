<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\GatherCore\Entity\ModerationLog;
use Fagathe\GatherCore\Enum\Moderation\ModerationActionType;

/**
 * @extends ServiceEntityRepository<ModerationLog>
 */
class ModerationLogRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModerationLog::class);
    }

    /**
     * @return array{items: list<ModerationLog>, page: int, hasMore: bool}
     */
    public function search(
        ?string $moderator,
        ?string $target,
        ?ModerationActionType $action,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        int $page,
    ): array {
        $page = max(1, $page);
        $qb = $this->createQueryBuilder('l')
            ->addSelect('moderator', 'target')
            ->innerJoin('l.moderator', 'moderator')
            ->leftJoin('l.targetUser', 'target')
            ->orderBy('l.createdAt', 'DESC');

        if ($moderator !== null && $moderator !== '') {
            $qb->andWhere('moderator.username LIKE :moderator')->setParameter('moderator', addcslashes($moderator, '%_') . '%');
        }
        if ($target !== null && $target !== '') {
            $qb->andWhere('target.username LIKE :target')->setParameter('target', addcslashes($target, '%_') . '%');
        }
        if ($action !== null) {
            $qb->andWhere('l.actionType = :action')->setParameter('action', $action);
        }
        if ($from !== null) {
            $qb->andWhere('l.createdAt >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('l.createdAt <= :to')->setParameter('to', $to);
        }

        $items = $qb->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE + 1)->getQuery()->getResult();

        return [
            'items' => array_slice($items, 0, self::PER_PAGE),
            'page' => $page,
            'hasMore' => count($items) > self::PER_PAGE,
        ];
    }
}
