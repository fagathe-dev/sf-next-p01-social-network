<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\GatherCore\Entity\SupportTicket;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Support\TicketCategory;
use Fagathe\GatherCore\Enum\Support\TicketPriority;
use Fagathe\GatherCore\Enum\Support\TicketStatus;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<SupportTicket>
 */
class SupportTicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupportTicket::class);
    }

    /** @return list<SupportTicket> */
    public function forUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->setParameter('user', $user->getId(), UuidType::NAME)
            ->orderBy('t.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<SupportTicket> */
    public function desk(?TicketStatus $status, ?TicketPriority $priority, ?TicketCategory $category, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('u')
            ->leftJoin('t.user', 'u')
            ->orderBy('t.updatedAt', 'DESC')
            ->setMaxResults($limit);

        if ($status !== null) {
            $qb->andWhere('t.status = :status')->setParameter('status', $status);
        }
        if ($priority !== null) {
            $qb->andWhere('t.priority = :priority')->setParameter('priority', $priority);
        }
        if ($category !== null) {
            $qb->andWhere('t.category = :category')->setParameter('category', $category);
        }

        return $qb->getQuery()->getResult();
    }
}
