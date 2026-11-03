<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\GatherCore\Entity\SocialGroup;
use Fagathe\GatherCore\Enum\Social\GroupVisibility;
use Fagathe\GatherCore\Security\Audience;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SocialGroup>
 */
class SocialGroupRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 24;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialGroup::class);
    }

    /**
     * Les groupes privés n'apparaissent que pour leurs membres.
     *
     * @return array{items: list<SocialGroup>, page: int, hasMore: bool}
     */
    public function listVisible(Audience $audience, int $page, bool $onlyMine = false): array
    {
        $page = max(1, $page);
        $hasGroups = $audience->groupIds !== [];
        if ($onlyMine && !$hasGroups) {
            return ['items' => [], 'page' => $page, 'hasMore' => false];
        }

        $qb = $this->createQueryBuilder('g')->orderBy('g.membersCount', 'DESC')->addOrderBy('g.createdAt', 'DESC');

        if ($hasGroups) {
            $qb->setParameter('mine', array_map(static fn(Uuid $id) => $id->toBinary(), $audience->groupIds), ArrayParameterType::BINARY);
        }

        if ($onlyMine) {
            $qb->where('g.id IN (:mine)');
        } elseif ($hasGroups) {
            $qb->where('g.visibility = :public OR g.id IN (:mine)')->setParameter('public', GroupVisibility::Public);
        } else {
            $qb->where('g.visibility = :public')->setParameter('public', GroupVisibility::Public);
        }

        $items = $qb->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE + 1)->getQuery()->getResult();

        return [
            'items' => array_slice($items, 0, self::PER_PAGE),
            'page' => $page,
            'hasMore' => count($items) > self::PER_PAGE,
        ];
    }
}
