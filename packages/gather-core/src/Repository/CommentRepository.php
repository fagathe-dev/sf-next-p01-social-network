<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\GatherCore\Entity\Comment;
use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Enum\Social\ContentStatus;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 30;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * @return array{items: list<Comment>, page: int, hasMore: bool}
     */
    public function listForPost(Post $post, int $page): array
    {
        $page = max(1, $page);
        $items = $this->createQueryBuilder('c')
            ->addSelect('author')
            ->innerJoin('c.author', 'author')
            ->where('c.post = :post AND c.status = :status AND author.deactivatedAt IS NULL')
            ->setParameter('post', $post->getId(), UuidType::NAME)
            ->setParameter('status', ContentStatus::Published)
            ->orderBy('c.createdAt', 'ASC')
            ->setFirstResult(($page - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE + 1)
            ->getQuery()
            ->getResult();

        return [
            'items' => array_slice($items, 0, self::PER_PAGE),
            'page' => $page,
            'hasMore' => count($items) > self::PER_PAGE,
        ];
    }
}
