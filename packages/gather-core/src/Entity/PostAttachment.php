<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Enum\Social\AttachmentType;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'post_attachment')]
class PostAttachment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'attachments')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Post $post,

        #[ORM\Column(length: 20, enumType: AttachmentType::class)]
        private AttachmentType $type,

        // Relatif à la racine publique ou privée selon Post::hasPublicMedia()
        #[ORM\Column(length: 512)]
        private string $filePath,

        #[ORM\Column(length: 255)]
        private string $originalName,

        #[ORM\Column(length: 120)]
        private string $mimeType,

        #[ORM\Column]
        private int $fileSize,

        #[ORM\Column]
        private int $position = 0,

        #[ORM\Column(length: 512, nullable: true)]
        private ?string $thumbnailPath = null,

        /** @var array{width?: int, height?: int, duration?: float} */
        #[ORM\Column(type: Types::JSON)]
        private array $metadata = [],
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getType(): AttachmentType
    {
        return $this->type;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getThumbnailPath(): ?string
    {
        return $this->thumbnailPath;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    /** @return array{width?: int, height?: int, duration?: float} */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
