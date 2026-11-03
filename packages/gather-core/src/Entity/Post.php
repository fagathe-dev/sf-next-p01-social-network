<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Enum\Social\ContentStatus;
use Fagathe\GatherCore\Enum\Social\PostVisibility;
use Fagathe\GatherCore\Repository\PostRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'post')]
#[ORM\Index(name: 'idx_post_search', columns: ['title', 'content', 'location_name'], flags: ['fulltext'])]
#[ORM\Index(name: 'idx_post_popular', columns: ['status', 'visibility', 'created_at', 'popularity_score'])]
class Post
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 20, enumType: ContentStatus::class)]
    private ContentStatus $status = ContentStatus::Published;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $locationName = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'group_id', nullable: true, onDelete: 'CASCADE')]
    private ?SocialGroup $group = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LinkPreview $linkPreview = null;

    #[ORM\Column]
    private int $viewsCount = 0;

    #[ORM\Column]
    private int $likesCount = 0;

    #[ORM\Column]
    private int $commentsCount = 0;

    #[ORM\Column]
    private int $bookmarksCount = 0;

    #[ORM\Column]
    private int $reportsCount = 0;

    #[ORM\Column]
    private float $popularityScore = 0.0;

    // Republication : la publication d'origine (toujours la racine, jamais une autre republication)
    #[ORM\ManyToOne(targetEntity: Post::class)]
    #[ORM\JoinColumn(name: 'original_post_id', nullable: true, onDelete: 'SET NULL')]
    private ?Post $originalPost = null;

    // Reste vrai si l'original disparaît : la republication affiche alors « publication indisponible »
    #[ORM\Column]
    private bool $isRepost = false;

    #[ORM\Column]
    private int $repostsCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'post_tag')]
    private Collection $tags;

    /** @var Collection<int, PostAttachment> */
    #[ORM\OneToMany(targetEntity: PostAttachment::class, mappedBy: 'post', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $attachments;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private User $author,

        #[ORM\Column(length: 255)]
        private string $slug,

        #[ORM\Column(type: Types::TEXT)]
        private string $content,

        #[ORM\Column(length: 20, enumType: PostVisibility::class)]
        private PostVisibility $visibility = PostVisibility::Public,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->tags = new ArrayCollection();
        $this->attachments = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getVisibility(): PostVisibility
    {
        return $this->visibility;
    }

    public function getStatus(): ContentStatus
    {
        return $this->status;
    }

    public function setStatus(ContentStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->status === ContentStatus::Published;
    }

    public function getLocationName(): ?string
    {
        return $this->locationName;
    }

    public function setLocationName(?string $locationName): static
    {
        $this->locationName = $locationName;

        return $this;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getGroup(): ?SocialGroup
    {
        return $this->group;
    }

    public function setGroup(?SocialGroup $group): static
    {
        $this->group = $group;

        return $this;
    }

    public function getLinkPreview(): ?LinkPreview
    {
        return $this->linkPreview;
    }

    public function setLinkPreview(?LinkPreview $linkPreview): static
    {
        $this->linkPreview = $linkPreview;

        return $this;
    }

    public function getViewsCount(): int
    {
        return $this->viewsCount;
    }

    public function getLikesCount(): int
    {
        return $this->likesCount;
    }

    public function getCommentsCount(): int
    {
        return $this->commentsCount;
    }

    public function getBookmarksCount(): int
    {
        return $this->bookmarksCount;
    }

    public function getReportsCount(): int
    {
        return $this->reportsCount;
    }

    public function getPopularityScore(): float
    {
        return $this->popularityScore;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    /** @return Collection<int, PostAttachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(PostAttachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
        }

        return $this;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function setVisibility(PostVisibility $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getOriginalPost(): ?Post
    {
        return $this->originalPost;
    }

    public function setOriginalPost(Post $originalPost): static
    {
        $this->originalPost = $originalPost;
        $this->isRepost = true;

        return $this;
    }

    public function isRepost(): bool
    {
        return $this->isRepost;
    }

    public function getRepostsCount(): int
    {
        return $this->repostsCount;
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

    public function clearTags(): static
    {
        $this->tags->clear();

        return $this;
    }

    public function removeAttachment(PostAttachment $attachment): static
    {
        $this->attachments->removeElement($attachment);

        return $this;
    }

    /** Seul un contenu lisible par tous se republie : jamais un groupe privé, des amis ou un compte privé. */
    public function isRepostable(): bool
    {
        if ($this->isRepost || !$this->isPublished()) {
            return false;
        }
        if ($this->group !== null) {
            return $this->group->isPublic();
        }

        return $this->visibility === PostVisibility::Public && !$this->author->isPrivate();
    }

    /** Média visible sans contrôle d'accès : post public hors groupe privé. */
    public function hasPublicMedia(): bool
    {
        return $this->visibility === PostVisibility::Public && ($this->group === null || $this->group->isPublic());
    }
}
