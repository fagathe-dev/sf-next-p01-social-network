<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Output;

final readonly class PostCardOutput
{
    /**
     * @param list<AttachmentOutput>                $attachments
     * @param list<array{name: string, slug: string}> $tags
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $path,
        public ?string $title,
        public string $content,
        public string $visibility,
        public string $status,
        public ?string $locationName,
        public UserSummaryOutput $author,
        public ?GroupCardOutput $group,
        public array $attachments,
        public array $tags,
        public ?LinkPreviewOutput $linkPreview,
        public int $viewsCount,
        public int $likesCount,
        public int $commentsCount,
        public int $bookmarksCount,
        public bool $likedByMe,
        public bool $bookmarkedByMe,
        public bool $canEngage,
        public bool $canManage,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $editedAt = null,
        public int $repostsCount = 0,
        public bool $repostedByMe = false,
        public bool $canEdit = false,
        public bool $canRepost = false,
        public bool $isRepost = false,
        // Publication republiée ; null si elle a disparu ou n'est plus visible pour ce lecteur
        public ?PostCardOutput $originalPost = null,
    ) {}
}
