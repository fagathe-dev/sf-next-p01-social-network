<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Fagathe\CorePhp\Http\ResponseTrait;
use Fagathe\GatherCore\Dto\Output\CommentOutput;
use Fagathe\GatherCore\Entity\Comment;
use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Enum\Social\ContentStatus;
use Fagathe\GatherCore\Repository\PostRepository;
use Fagathe\GatherCore\Security\Audience;
use Fagathe\GatherCore\Security\AudienceResolver;
use Symfony\Component\Uid\Uuid;

/**
 * Journal strictement personnel : chaque requête lit le membre de la session, jamais un identifiant transmis.
 * Un contenu devenu invisible pour lui (supprimé, passé en privé) n'y apparaît plus.
 */
final class ActivityService
{
    use ResponseTrait;

    private const PER_PAGE = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AudienceResolver $audiences,
        private readonly PostRepository $posts,
        private readonly PostPresenter $presenter,
        private readonly PostService $postService,
    ) {}

    /** Publications et commentaires aimés, du plus récent au plus ancien. */
    public function likes(int $page): object
    {
        $audience = $this->audiences->current();
        $rows = $this->rows(
            'SELECT kind, target_id, created_at FROM (
                SELECT \'post\' AS kind, post_id AS target_id, created_at FROM post_like WHERE user_id = :me
                UNION ALL
                SELECT \'comment\' AS kind, comment_id AS target_id, created_at FROM comment_like WHERE user_id = :me
            ) likes ORDER BY created_at DESC',
            $audience,
            $page
        );

        $posts = $this->visiblePostCards(array_column(array_filter($rows['items'], static fn($r) => $r['kind'] === 'post'), 'target_id'), $audience);
        $comments = $this->visibleComments(array_column(array_filter($rows['items'], static fn($r) => $r['kind'] === 'comment'), 'target_id'), $audience, true);

        $items = [];
        foreach ($rows['items'] as $row) {
            $key = Uuid::fromBinary($row['target_id'])->toRfc4122();
            $entry = $row['kind'] === 'post' ? ($posts[$key] ?? null) : ($comments[$key] ?? null);
            if ($entry !== null) {
                $items[] = ['kind' => $row['kind'], 'likedAt' => $row['created_at'], $row['kind'] => $entry];
            }
        }

        return $this->sendJson(['items' => $items, 'page' => $rows['page'], 'hasMore' => $rows['hasMore']]);
    }

    /** Commentaires et réponses publiés, avec la publication d'origine. */
    public function comments(int $page): object
    {
        $audience = $this->audiences->current();
        $rows = $this->rows(
            "SELECT id AS target_id, created_at FROM comment WHERE author_id = :me AND status = 'published' ORDER BY created_at DESC",
            $audience,
            $page
        );
        $comments = $this->visibleComments(array_column($rows['items'], 'target_id'), $audience, false);

        return $this->sendJson(['items' => array_values($comments), 'page' => $rows['page'], 'hasMore' => $rows['hasMore']]);
    }

    public function reposts(int $page): object
    {
        $audience = $this->audiences->current();
        $rows = $this->rows(
            "SELECT id AS target_id, created_at FROM post WHERE author_id = :me AND original_post_id IS NOT NULL AND status = 'published' ORDER BY created_at DESC",
            $audience,
            $page
        );

        return $this->sendJson(['items' => array_values($this->visiblePostCards(array_column($rows['items'], 'target_id'), $audience)), 'page' => $rows['page'], 'hasMore' => $rows['hasMore']]);
    }

    /** Publications consultées sur leur page dédiée, de la plus récente à la plus ancienne. */
    public function views(int $page): object
    {
        $audience = $this->audiences->current();
        $rows = $this->rows('SELECT post_id AS target_id, viewed_at AS created_at FROM post_view WHERE user_id = :me ORDER BY viewed_at DESC', $audience, $page);
        $cards = $this->visiblePostCards(array_column($rows['items'], 'target_id'), $audience);

        $items = [];
        foreach ($rows['items'] as $row) {
            $card = $cards[Uuid::fromBinary($row['target_id'])->toRfc4122()] ?? null;
            if ($card !== null) {
                $items[] = ['viewedAt' => $row['created_at'], 'post' => $card];
            }
        }

        return $this->sendJson(['items' => $items, 'page' => $rows['page'], 'hasMore' => $rows['hasMore']]);
    }

    public function bookmarks(int $page): object
    {
        return $this->postService->listBookmarks($page, null);
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, hasMore: bool}
     */
    private function rows(string $sql, Audience $audience, int $page): array
    {
        $page = max(1, $page);
        $items = $this->em->getConnection()->fetchAllAssociative(
            sprintf('%s LIMIT %d OFFSET %d', $sql, self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE),
            ['me' => $audience->user->getId()->toBinary()]
        );

        return ['items' => array_slice($items, 0, self::PER_PAGE), 'page' => $page, 'hasMore' => count($items) > self::PER_PAGE];
    }

    /**
     * @param list<string> $binaryIds
     *
     * @return array<string, \Fagathe\GatherCore\Dto\Output\PostCardOutput> indexé par identifiant
     */
    private function visiblePostCards(array $binaryIds, Audience $audience): array
    {
        if ($binaryIds === []) {
            return [];
        }

        $posts = $this->posts->findVisibleByIds(array_map(static fn(string $id) => Uuid::fromBinary($id), $binaryIds), $audience);
        $cards = [];
        foreach ($this->presenter->cards($posts, $audience) as $card) {
            $cards[$card->id] = $card;
        }

        return $cards;
    }

    /**
     * @param list<string> $binaryIds
     *
     * @return array<string, array<string, mixed>> indexé par identifiant, dans l'ordre reçu
     */
    private function visibleComments(array $binaryIds, Audience $audience, bool $liked): array
    {
        if ($binaryIds === []) {
            return [];
        }

        /** @var list<Comment> $found */
        $found = $this->em->createQueryBuilder()
            ->select('c', 'p', 'a')
            ->from(Comment::class, 'c')
            ->innerJoin('c.post', 'p')
            ->innerJoin('c.author', 'a')
            ->where('c.id IN (:ids) AND c.status = :published')
            ->setParameter('ids', $binaryIds, ArrayParameterType::BINARY)
            ->setParameter('published', ContentStatus::Published)
            ->getQuery()
            ->getResult();

        $likedIds = $liked ? array_fill_keys($binaryIds, true) : $this->postService->likedCommentIds($found, $audience);
        $byId = [];
        foreach ($found as $comment) {
            if (!$audience->canViewPost($comment->getPost())) {
                continue;
            }
            $byId[(string) $comment->getId()] = [
                ...(array) CommentOutput::from($comment, isset($likedIds[$comment->getId()->toBinary()])),
                'post' => $this->postSummary($comment->getPost()),
            ];
        }

        $ordered = [];
        foreach ($binaryIds as $binaryId) {
            $key = Uuid::fromBinary($binaryId)->toRfc4122();
            if (isset($byId[$key])) {
                $ordered[$key] = $byId[$key];
            }
        }

        return $ordered;
    }

    /** @return array{id: string, path: string, title: ?string, excerpt: string, author: string} */
    private function postSummary(Post $post): array
    {
        $text = $post->getContent() !== '' ? $post->getContent() : ($post->getOriginalPost()?->getContent() ?? '');

        return [
            'id' => (string) $post->getId(),
            'path' => sprintf('/post/%s-%s', $post->getSlug(), $post->getId()),
            'title' => $post->getTitle(),
            'excerpt' => mb_strlen($text) > 140 ? mb_substr($text, 0, 140) . '…' : $text,
            'author' => (string) $post->getAuthor()->getDisplayName(),
        ];
    }
}
