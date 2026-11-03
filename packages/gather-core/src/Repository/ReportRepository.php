<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\GatherCore\Entity\Report;
use Fagathe\GatherCore\Enum\Moderation\ReportStatus;
use Fagathe\GatherCore\Enum\Moderation\ReportTargetType;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Report>
 */
class ReportRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Report::class);
    }

    public function countDistinctReporters(ReportTargetType $type, Uuid $targetId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.reporter)')
            ->where('r.targetType = :type AND r.targetId = :target')
            ->setParameter('type', $type)
            ->setParameter('target', $targetId, UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * File de modération : les contenus masqués automatiquement passent en tête.
     *
     * @return array{items: list<Report>, page: int, hasMore: bool}
     */
    public function queue(?ReportStatus $status, ?ReportTargetType $type, int $page): array
    {
        $page = max(1, $page);
        $qb = $this->createQueryBuilder('r')
            ->addSelect('reporter', 'CASE WHEN r.status = :hidden THEN 0 ELSE 1 END AS HIDDEN priority')
            ->innerJoin('r.reporter', 'reporter')
            ->setParameter('hidden', ReportStatus::AutoHidden)
            ->orderBy('priority', 'ASC')
            ->addOrderBy('r.createdAt', 'DESC');

        if ($status !== null) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }
        if ($type !== null) {
            $qb->andWhere('r.targetType = :type')->setParameter('type', $type);
        }

        $items = $qb->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE + 1)->getQuery()->getResult();

        return [
            'items' => array_slice($items, 0, self::PER_PAGE),
            'page' => $page,
            'hasMore' => count($items) > self::PER_PAGE,
        ];
    }

    /** @return list<Report> signalements encore ouverts sur une même cible */
    public function openForTarget(ReportTargetType $type, Uuid $targetId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.targetType = :type AND r.targetId = :target AND r.status != :resolved')
            ->setParameter('type', $type)
            ->setParameter('target', $targetId, UuidType::NAME)
            ->setParameter('resolved', ReportStatus::Resolved)
            ->getQuery()
            ->getResult();
    }
}
