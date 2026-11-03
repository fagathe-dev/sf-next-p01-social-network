<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Fagathe\GatherCore\Dto\Output\GroupCardOutput;
use Fagathe\GatherCore\Dto\Output\LinkPreviewOutput;
use Fagathe\GatherCore\Dto\Output\PostCardOutput;
use Fagathe\GatherCore\Dto\Output\UserSummaryOutput;
use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Entity\PostAttachment;
use Fagathe\GatherCore\Entity\Tag;
use Fagathe\GatherCore\Security\Audience;

final class PostPresenter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttachmentStorageService $attachments,
    ) {}

    public function card(Post $post, Audience $audience): PostCardOutput
    {
        return $this->cards([$post], $audience)[0];
    }

    /**
     * @param list<Post> $posts
     *
     * @return list<PostCardOutput>
     */
    public function cards(array $posts, Audience $audience): array
    {
        $liked = $this->flaggedPostIds('post_like', $posts, $audience);
        $bookmarked = $this->flaggedPostIds('bookmark', $posts, $audience);
        $reposted = $this->repostedPostIds($posts, $audience);

        // Publications republiées encore visibles : présentées une seule fois pour toute la page
        $originals = [];
        foreach ($posts as $post) {
            $original = $post->getOriginalPost();
            if ($original !== null && $audience->canViewPost($original)) {
                $originals[(string) $original->getId()] = $original;
            }
        }
        $originalCards = [];
        if ($originals !== []) {
            foreach ($this->cards(array_values($originals), $audience) as $card) {
                $originalCards[$card->id] = $card;
            }
        }

        return array_map(function (Post $post) use ($audience, $liked, $bookmarked, $reposted, $originalCards): PostCardOutput {
            $binaryId = $post->getId()->toBinary();

            return new PostCardOutput(
                (string) $post->getId(),
                $post->getSlug(),
                sprintf('/post/%s-%s', $post->getSlug(), $post->getId()),
                $post->getTitle(),
                $post->getContent(),
                $post->getVisibility()->value,
                $post->getStatus()->value,
                $post->getLocationName(),
                UserSummaryOutput::from($post->getAuthor()),
                $post->getGroup() !== null ? GroupCardOutput::from($post->getGroup()) : null,
                array_values($post->getAttachments()->map(fn(PostAttachment $a) => $this->attachments->output($a))->toArray()),
                array_values($post->getTags()->map(static fn(Tag $t) => ['name' => $t->getName(), 'slug' => $t->getSlug()])->toArray()),
                $post->getLinkPreview() !== null ? LinkPreviewOutput::from($post->getLinkPreview()) : null,
                $post->getViewsCount(),
                $post->getLikesCount(),
                $post->getCommentsCount(),
                $post->getBookmarksCount(),
                isset($liked[$binaryId]),
                isset($bookmarked[$binaryId]),
                $audience->canEngage($post),
                $audience->isSelf($post->getAuthor()) || $audience->isStaff(),
                $post->getCreatedAt(),
                $post->getEditedAt(),
                $post->getRepostsCount(),
                isset($reposted[$binaryId]),
                // Modifier reste réservé à l'auteur : le staff modère, il ne réécrit pas
                $audience->isSelf($post->getAuthor()) && $post->isPublished() && $audience->canWrite(),
                $audience->canWrite() && !$audience->isSelf($post->getAuthor()) && $post->isRepostable(),
                $post->isRepost(),
                $post->getOriginalPost() !== null ? ($originalCards[(string) $post->getOriginalPost()->getId()] ?? null) : null,
            );
        }, $posts);
    }

    /**
     * @param list<Post> $posts
     *
     * @return array<string, true> publications que le lecteur a déjà republiées, indexées par identifiant binaire
     */
    private function repostedPostIds(array $posts, Audience $audience): array
    {
        if ($audience->user === null || $posts === []) {
            return [];
        }

        $ids = $this->em->getConnection()->fetchFirstColumn(
            "SELECT original_post_id FROM post WHERE author_id = :user AND status = 'published' AND original_post_id IN (:posts)",
            [
                'user' => $audience->user->getId()->toBinary(),
                'posts' => array_map(static fn(Post $post) => $post->getId()->toBinary(), $posts),
            ],
            ['posts' => ArrayParameterType::BINARY]
        );

        return array_fill_keys($ids, true);
    }

    /**
     * Une requête par table pour toute la page, plutôt qu'une par carte.
     *
     * @param list<Post> $posts
     *
     * @return array<string, true> indexé par identifiant binaire
     */
    private function flaggedPostIds(string $table, array $posts, Audience $audience): array
    {
        if ($audience->user === null || $posts === []) {
            return [];
        }

        $ids = $this->em->getConnection()->fetchFirstColumn(
            sprintf('SELECT post_id FROM %s WHERE user_id = :user AND post_id IN (:posts)', $table),
            [
                'user' => $audience->user->getId()->toBinary(),
                'posts' => array_map(static fn(Post $post) => $post->getId()->toBinary(), $posts),
            ],
            ['posts' => ArrayParameterType::BINARY]
        );

        return array_fill_keys($ids, true);
    }
}
