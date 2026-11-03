<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Repository\LinkPreviewRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: LinkPreviewRepository::class)]
#[ORM\Table(name: 'link_preview')]
#[ORM\UniqueConstraint(name: 'uniq_link_preview_hash', columns: ['url_hash'])]
class LinkPreview
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $urlHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 2048)]
        private string $url,

        #[ORM\Column(length: 255)]
        private string $domain,

        #[ORM\Column(length: 60, nullable: true)]
        private ?string $ogType = null,

        #[ORM\Column(length: 255, nullable: true)]
        private ?string $title = null,

        #[ORM\Column(length: 1024, nullable: true)]
        private ?string $description = null,

        #[ORM\Column(length: 2048, nullable: true)]
        private ?string $imageUrl = null,

        #[ORM\Column(length: 255, nullable: true)]
        private ?string $siteName = null,
    ) {
        $this->id = Uuid::v7();
        $this->urlHash = self::hash($url);
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function hash(string $url): string
    {
        return hash('sha256', $url);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUrlHash(): string
    {
        return $this->urlHash;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getOgType(): ?string
    {
        return $this->ogType;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getSiteName(): ?string
    {
        return $this->siteName;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
