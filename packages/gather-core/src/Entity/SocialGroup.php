<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Enum\Social\GroupVisibility;
use Fagathe\GatherCore\Enum\Social\ThemePalette;
use Fagathe\GatherCore\Repository\SocialGroupRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SocialGroupRepository::class)]
#[ORM\Table(name: 'social_group')]
#[ORM\UniqueConstraint(name: 'uniq_group_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_group_search', columns: ['name', 'description'], flags: ['fulltext'])]
#[ORM\Index(name: 'idx_group_vis', columns: ['visibility', 'slug'])]
class SocialGroup
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: ThemePalette::class)]
    private ThemePalette $themePalette = ThemePalette::Indigo;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $iconName = null;

    #[ORM\Column(length: 20, enumType: GroupVisibility::class)]
    private GroupVisibility $visibility = GroupVisibility::Public;

    #[ORM\Column]
    private int $membersCount = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 120)]
        private string $name,

        #[ORM\Column(length: 140)]
        private string $slug,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private User $owner,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getThemePalette(): ThemePalette
    {
        return $this->themePalette;
    }

    public function setThemePalette(ThemePalette $themePalette): static
    {
        $this->themePalette = $themePalette;

        return $this;
    }

    public function getIconName(): ?string
    {
        return $this->iconName;
    }

    public function setIconName(?string $iconName): static
    {
        $this->iconName = $iconName;

        return $this;
    }

    public function getVisibility(): GroupVisibility
    {
        return $this->visibility;
    }

    public function setVisibility(GroupVisibility $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function isPublic(): bool
    {
        return $this->visibility === GroupVisibility::Public;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getMembersCount(): int
    {
        return $this->membersCount;
    }

    public function setMembersCount(int $membersCount): static
    {
        $this->membersCount = max(0, $membersCount);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
