<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\GatherCore\Entity\Conversation;
use Fagathe\GatherCore\Entity\Message;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Page 1 = messages les plus récents ; renvoyés du plus ancien au plus récent pour l'affichage.
     *
     * @return array{items: list<Message>, page: int, hasMore: bool}
     */
    public function listFor(Conversation $conversation, int $page): array
    {
        $page = max(1, $page);
        $items = $this->createQueryBuilder('m')
            ->addSelect('sender')
            ->innerJoin('m.sender', 'sender')
            ->where('m.conversation = :conversation')
            ->setParameter('conversation', $conversation->getId(), UuidType::NAME)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE + 1)
            ->getQuery()
            ->getResult();

        return [
            'items' => array_reverse(array_slice($items, 0, self::PER_PAGE)),
            'page' => $page,
            'hasMore' => count($items) > self::PER_PAGE,
        ];
    }

    /** @return list<Message> Du plus récent au plus ancien */
    public function attachmentsOf(Conversation $conversation, int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('sender')
            ->innerJoin('m.sender', 'sender')
            ->where('m.conversation = :conversation AND m.attachmentPath IS NOT NULL')
            ->setParameter('conversation', $conversation->getId(), UuidType::NAME)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Message> Du plus récent au plus ancien */
    public function searchIn(Conversation $conversation, string $query, int $limit = 30): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('sender')
            ->innerJoin('m.sender', 'sender')
            ->where('m.conversation = :conversation AND m.content LIKE :query')
            ->setParameter('conversation', $conversation->getId(), UuidType::NAME)
            ->setParameter('query', '%' . addcslashes($query, '%_\\') . '%')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function latestOf(Conversation $conversation): ?Message
    {
        return $this->findOneBy(['conversation' => $conversation], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
