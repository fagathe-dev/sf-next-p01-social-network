<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Output;

final readonly class AttachmentOutput
{
    /**
     * @param array{width?: int, height?: int, duration?: float} $metadata
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $url,
        public ?string $thumbnailUrl,
        public string $originalName,
        public string $mimeType,
        public int $fileSize,
        public bool $isPublic,
        public array $metadata,
    ) {}
}
