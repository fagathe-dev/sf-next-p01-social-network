<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Enum\Social\ContentStatus;
use Fagathe\GatherCore\Repository\CommentRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comment')]
#[ORM\Index(name: 'idx_comment_post', columns: ['post_id', 'status', 'created_at'])]
#[ORM\Index(name: 'idx_comment_author', columns: ['author_id', 'created_at'])]
class Comment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: ContentStatus::class)]
    private ContentStatus $status = ContentStatus::Published;

    #[ORM\Column]
    private int $reportsCount = 0;

    #[ORM\Column]
    private int $likesCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Post $post,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $author,

        #[ORM\Column(type: Types::TEXT)]
        private string $content,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Comment $parentComment = null,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getParentComment(): ?Comment
    {
        return $this->parentComment;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getStatus(): ContentStatus
    {
        return $this->status;
    }

    public function isPublished(): bool
    {
        return $this->status === ContentStatus::Published;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getLikesCount(): int
    {
        return $this->likesCount;
    }

    public function getEditedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function markEdited(): static
    {
        $this->editedAt = new \DateTimeImmutable();

        return $this;
    }

    public function setStatus(ContentStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getReportsCount(): int
    {
        return $this->reportsCount;
    }

    public function incrementReportsCount(): int
    {
        return ++$this->reportsCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
