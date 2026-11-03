<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\GatherCore\Entity\Conversation;
use Fagathe\GatherCore\Entity\ConversationParticipant;
use Fagathe\GatherCore\Entity\User;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /** @return list<Conversation> */
    public function forUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin(ConversationParticipant::class, 'mine', 'WITH', 'mine.conversation = c AND mine.user = :user')
            ->setParameter('user', $user->getId(), UuidType::NAME)
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Conversation privée déjà ouverte entre deux personnes, pour ne pas en créer une seconde. */
    public function findDirectBetween(User $a, User $b): ?Conversation
    {
        return $this->createQueryBuilder('c')
            ->innerJoin(ConversationParticipant::class, 'pa', 'WITH', 'pa.conversation = c AND pa.user = :a')
            ->innerJoin(ConversationParticipant::class, 'pb', 'WITH', 'pb.conversation = c AND pb.user = :b')
            ->where('c.title IS NULL')
            ->andWhere('(SELECT COUNT(p.id) FROM ' . ConversationParticipant::class . ' p WHERE p.conversation = c) = 2')
            ->setParameter('a', $a->getId(), UuidType::NAME)
            ->setParameter('b', $b->getId(), UuidType::NAME)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function unreadTotal(User $user): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COALESCE(SUM(p.unreadCount), 0)')
            ->from(ConversationParticipant::class, 'p')
            ->where('p.user = :user')
            ->setParameter('user', $user->getId(), UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
