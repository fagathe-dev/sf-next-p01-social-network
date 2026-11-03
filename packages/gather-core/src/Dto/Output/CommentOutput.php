<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Output;

use Fagathe\GatherCore\Entity\Comment;

final readonly class CommentOutput
{
    public function __construct(
        public string $id,
        public string $content,
        public ?string $parentId,
        public UserSummaryOutput $author,
        public \DateTimeImmutable $createdAt,
        public int $likesCount = 0,
        public bool $likedByMe = false,
        public ?\DateTimeImmutable $editedAt = null,
    ) {}

    public static function from(Comment $comment, bool $likedByMe = false): self
    {
        return new self(
            (string) $comment->getId(),
            $comment->getContent(),
            $comment->getParentComment() !== null ? (string) $comment->getParentComment()->getId() : null,
            UserSummaryOutput::from($comment->getAuthor()),
            $comment->getCreatedAt(),
            $comment->getLikesCount(),
            $likedByMe,
            $comment->getEditedAt(),
        );
    }
}
