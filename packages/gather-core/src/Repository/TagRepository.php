<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\GatherCore\Entity\Tag;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /** @return list<Tag> Tags commençant par le texte saisi, les plus tendance d'abord */
    public function searchByPrefix(string $prefix, int $limit = 10): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.name LIKE :prefix')
            ->setParameter('prefix', addcslashes(mb_strtolower($prefix), '%_\\') . '%')
            ->orderBy('t.trendingScore', 'DESC')
            ->addOrderBy('t.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Tag> */
    public function trending(int $limit = 10): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.trendingScore > 0')
            ->orderBy('t.trendingScore', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
