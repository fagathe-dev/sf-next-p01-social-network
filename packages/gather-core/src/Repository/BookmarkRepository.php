<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\GatherCore\Entity\Bookmark;
use Fagathe\GatherCore\Entity\User;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<Bookmark>
 */
class BookmarkRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 20;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bookmark::class);
    }

    /**
     * @return array{items: list<Bookmark>, page: int, hasMore: bool}
     */
    public function listFor(User $user, int $page, ?string $collection = null, bool $onlyPublic = false): array
    {
        $page = max(1, $page);
        $qb = $this->createQueryBuilder('b')
            ->where('b.user = :user')
            ->setParameter('user', $user->getId(), UuidType::NAME)
            ->orderBy('b.createdAt', 'DESC');

        if ($collection !== null) {
            $qb->andWhere('b.collectionName = :collection')->setParameter('collection', $collection);
        }
        if ($onlyPublic) {
            $qb->andWhere('b.isPublicCollection = true');
        }

        $items = $qb->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE + 1)->getQuery()->getResult();

        return [
            'items' => array_slice($items, 0, self::PER_PAGE),
            'page' => $page,
            'hasMore' => count($items) > self::PER_PAGE,
        ];
    }

    /** @return list<array{name: string, isPublic: bool, count: int}> */
    public function collectionsOf(User $user): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('b.collectionName AS name', 'MAX(b.isPublicCollection) AS isPublic', 'COUNT(b.id) AS total')
            ->where('b.user = :user AND b.collectionName IS NOT NULL')
            ->setParameter('user', $user->getId(), UuidType::NAME)
            ->groupBy('b.collectionName')
            ->orderBy('b.collectionName', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn(array $row) => ['name' => $row['name'], 'isPublic' => (bool) $row['isPublic'], 'count' => (int) $row['total']],
            $rows
        );
    }
}
