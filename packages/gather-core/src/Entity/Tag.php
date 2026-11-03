<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Repository\TagRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tag')]
#[ORM\UniqueConstraint(name: 'uniq_tag_name', columns: ['name'])]
#[ORM\UniqueConstraint(name: 'uniq_tag_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_tag_trending', columns: ['name', 'trending_score'])]
class Tag
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'usage_count_7d')]
    private int $usageCount7d = 0;

    #[ORM\Column]
    private float $trendingScore = 0.0;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(length: 100)]
        private string $name,

        #[ORM\Column(length: 120)]
        private string $slug,
    ) {
        $this->id = Uuid::v7();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getUsageCount7d(): int
    {
        return $this->usageCount7d;
    }

    public function getTrendingScore(): float
    {
        return $this->trendingScore;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
