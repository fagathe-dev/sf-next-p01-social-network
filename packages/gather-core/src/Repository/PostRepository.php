<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Entity\SocialGroup;
use Fagathe\GatherCore\Entity\Tag;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Auth\UserStatus;
use Fagathe\GatherCore\Enum\Social\ContentStatus;
use Fagathe\GatherCore\Enum\Social\GroupVisibility;
use Fagathe\GatherCore\Enum\Social\PostVisibility;
use Fagathe\GatherCore\Security\Audience;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Post>
 */
class PostRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 20;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    /**
     * Onglet « Récents » : publications publiques et publications des groupes rejoints.
     * Les publications réservées aux amis et les republications vont dans « Réseau ».
     */
    public function recent(Audience $audience, int $page): array
    {
        return $this->paginate(
            $this->visibleQuery($audience)
                ->andWhere('p.isRepost = false AND p.visibility != :recent_friends_only')
                ->setParameter('recent_friends_only', PostVisibility::Friends)
                ->orderBy('p.createdAt', 'DESC')
                ->addOrderBy('p.id', 'DESC'),
            $page
        );
    }

    /** Onglet « Réseau » : uniquement les publications et republications des amis. */
    public function friendsNetwork(Audience $audience, int $page): array
    {
        if ($audience->user === null || $audience->friendIds === []) {
            return ['items' => [], 'page' => $page, 'hasMore' => false];
        }

        return $this->paginate(
            $this->visibleQuery($audience)
                ->andWhere('p.author IN (:network_friends)')
                ->setParameter('network_friends', self::binaryIds($audience->friendIds), ArrayParameterType::BINARY)->orderBy('p.createdAt', 'DESC')->addOrderBy('p.id', 'DESC'),
            $page
        );
    }

    /** Onglet « Tendances » : score calculé par PopularityCalculatorService sur 7 jours glissants. */
    public function popular(Audience $audience, int $page): array
    {
        return $this->paginate(
            $this->visibleQuery($audience)
                ->andWhere('p.isRepost = false')
                ->andWhere('p.createdAt >= :popular_since')
                ->setParameter('popular_since', new \DateTimeImmutable('-7 days'))
                ->orderBy('p.popularityScore', 'DESC')
                ->addOrderBy('p.createdAt', 'DESC'),
            $page
        );
    }

    public function byAuthor(User $author, Audience $audience, int $page): array
    {
        return $this->paginate(
            $this->visibleQuery($audience)
                ->andWhere('p.author = :filter_author')
                ->setParameter('filter_author', $author->getId(), UuidType::NAME)
                ->orderBy('p.createdAt', 'DESC'),
            $page
        );
    }

    public function byGroup(SocialGroup $group, Audience $audience, int $page): array
    {
        return $this->paginate(
            $this->visibleQuery($audience)
                ->andWhere('p.group = :filter_group')
                ->setParameter('filter_group', $group->getId(), UuidType::NAME)
                ->orderBy('p.createdAt', 'DESC'),
            $page
        );
    }

    public function byTag(Tag $tag, Audience $audience, int $page): array
    {
        return $this->paginate(
            $this->visibleQuery($audience)
                ->innerJoin('p.tags', 'filter_tag')
                ->andWhere('filter_tag.id = :filter_tag_id')
                ->setParameter('filter_tag_id', $tag->getId(), UuidType::NAME)
                ->orderBy('p.createdAt', 'DESC'),
            $page
        );
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<Post> dans l'ordre demandé, limités à ce que le visiteur peut lire
     */
    public function findVisibleByIds(array $ids, Audience $audience): array
    {
        if ($ids === []) {
            return [];
        }

        $posts = $this->visibleQuery($audience)
            ->andWhere('p.id IN (:visible_ids)')
            ->setParameter('visible_ids', self::binaryIds($ids), ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();
        $this->loadCollections($posts);

        $byId = [];
        foreach ($posts as $post) {
            $byId[(string) $post->getId()] = $post;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[(string) $id])) {
                $ordered[] = $byId[(string) $id];
            }
        }

        return $ordered;
    }

    /** Publications publiques indexables, pour le sitemap. */
    public function publicForSitemap(int $limit = 5000): array
    {
        return $this->visibleQuery(new Audience())
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Recompte les republications publiées : une suppression ou une modération ne fausse pas le total. */
    public function refreshRepostsCount(Post $original): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $count = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM post WHERE original_post_id = :id AND status = 'published'",
            ['id' => $original->getId()->toBinary()]
        );
        $connection->executeStatement('UPDATE post SET reposts_count = :count WHERE id = :id', ['count' => $count, 'id' => $original->getId()->toBinary()]);
        $this->getEntityManager()->refresh($original);
    }

    public function findRepost(User $author, Post $original): ?Post
    {
        return $this->createQueryBuilder('p')
            ->where('p.author = :author AND p.originalPost = :original AND p.status = :published')
            ->setParameter('author', $author->getId(), UuidType::NAME)
            ->setParameter('original', $original->getId(), UuidType::NAME)
            ->setParameter('published', ContentStatus::Published)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Ajuste un compteur en une seule requête SQL : deux likes simultanés ne s'écrasent pas.
     */
    public function adjustCounter(Post $post, string $counter, int $delta): void
    {
        $column = match ($counter) {
            'likes' => 'likes_count',
            'comments' => 'comments_count',
            'bookmarks' => 'bookmarks_count',
            'views' => 'views_count',
            'reports' => 'reports_count',
        };

        $this->getEntityManager()->getConnection()->executeStatement(
            sprintf('UPDATE post SET %1$s = GREATEST(0, %1$s + :delta) WHERE id = :id', $column),
            ['delta' => $delta, 'id' => $post->getId()->toBinary()]
        );
        $this->getEntityManager()->refresh($post);
    }

    private function visibleQuery(Audience $audience): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('author', 'grp', 'preview')
            ->innerJoin('p.author', 'author')
            ->leftJoin('p.group', 'grp')
            ->leftJoin('p.linkPreview', 'preview');

        return $this->applyVisibility($qb, $audience);
    }

    /**
     * Traduction SQL de Audience::canViewPost() pour les listes (hors droits étendus du staff).
     */
    private function applyVisibility(QueryBuilder $qb, Audience $audience): QueryBuilder
    {
        $qb->andWhere('p.status = :published')
            ->andWhere('author.status != :banned')
            ->andWhere('author.deactivatedAt IS NULL')
            ->setParameter('published', ContentStatus::Published)
            ->setParameter('banned', UserStatus::Banned);

        $rules = [
            '(p.group IS NULL AND p.visibility = :vis_public AND author.isPrivate = false)',
            'grp.visibility = :group_public',
        ];
        $qb->setParameter('vis_public', PostVisibility::Public)
            ->setParameter('group_public', GroupVisibility::Public);

        if ($audience->user !== null) {
            $rules[] = 'p.author = :viewer';
            $qb->setParameter('viewer', $audience->user->getId(), UuidType::NAME);
        }

        if ($audience->friendIds !== []) {
            $rules[] = '(p.group IS NULL AND p.author IN (:viewer_friends))';
            $qb->setParameter('viewer_friends', self::binaryIds($audience->friendIds), ArrayParameterType::BINARY);
        }

        if ($audience->groupIds !== []) {
            $rules[] = 'p.group IN (:viewer_groups)';
            $qb->setParameter('viewer_groups', self::binaryIds($audience->groupIds), ArrayParameterType::BINARY);
        }

        return $qb->andWhere('(' . implode(' OR ', $rules) . ')');
    }

    /**
     * @return array{items: list<Post>, page: int, hasMore: bool}
     */
    private function paginate(QueryBuilder $qb, int $page): array
    {
        $page = max(1, $page);
        $items = $qb->setFirstResult(($page - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE + 1)
            ->getQuery()
            ->getResult();

        $hasMore = count($items) > self::PER_PAGE;
        $items = array_slice($items, 0, self::PER_PAGE);
        $this->loadCollections($items);

        return ['items' => $items, 'page' => $page, 'hasMore' => $hasMore];
    }

    /**
     * Charge tags et fichiers en une requête plutôt qu'une par carte du flux.
     *
     * @param list<Post> $posts
     */
    private function loadCollections(array $posts): void
    {
        if ($posts === []) {
            return;
        }

        $this->createQueryBuilder('p')
            ->addSelect('attachment', 'tag')
            ->leftJoin('p.attachments', 'attachment')
            ->leftJoin('p.tags', 'tag')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', self::binaryIds(array_map(static fn(Post $post) => $post->getId(), $posts)), ArrayParameterType::BINARY)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    private static function binaryIds(array $ids): array
    {
        return array_map(static fn(Uuid $id) => $id->toBinary(), $ids);
    }
}
