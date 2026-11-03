<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Repository\BookmarkRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: BookmarkRepository::class)]
#[ORM\Table(name: 'bookmark')]
#[ORM\UniqueConstraint(name: 'uniq_bookmark', columns: ['post_id', 'user_id'])]
#[ORM\Index(name: 'idx_bookmark_user', columns: ['user_id', 'created_at'])]
class Bookmark
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $collectionName = null;

    #[ORM\Column]
    private bool $isPublicCollection = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Post $post,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCollectionName(): ?string
    {
        return $this->collectionName;
    }

    public function isPublicCollection(): bool
    {
        return $this->isPublicCollection;
    }

    public function moveToCollection(?string $collectionName, bool $isPublic = false): static
    {
        $this->collectionName = $collectionName;
        // Une publication hors collection n'a rien à partager
        $this->isPublicCollection = $collectionName !== null && $isPublic;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
