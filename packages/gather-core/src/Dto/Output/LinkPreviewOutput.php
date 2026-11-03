<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Output;

use Fagathe\GatherCore\Entity\LinkPreview;

final readonly class LinkPreviewOutput
{
    public function __construct(
        public string $id,
        public string $url,
        public string $domain,
        public ?string $ogType,
        public ?string $title,
        public ?string $description,
        public ?string $imageUrl,
        public ?string $siteName,
    ) {}

    public static function from(LinkPreview $preview): self
    {
        return new self(
            (string) $preview->getId(),
            $preview->getUrl(),
            $preview->getDomain(),
            $preview->getOgType(),
            $preview->getTitle(),
            $preview->getDescription(),
            $preview->getImageUrl(),
            $preview->getSiteName(),
        );
    }
}
