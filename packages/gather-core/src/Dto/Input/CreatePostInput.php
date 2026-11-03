<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Input;

use Fagathe\GatherCore\Enum\Social\PostVisibility;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreatePostInput
{
    public function __construct(
        #[Assert\Length(max: 20000, maxMessage: 'La publication ne peut pas dépasser {{ limit }} caractères.')]
        public string $content = '',

        #[Assert\Length(max: 255)]
        public ?string $title = null,

        public PostVisibility $visibility = PostVisibility::Public,

        public ?string $groupSlug = null,

        #[Assert\Length(max: 255)]
        public ?string $locationName = null,

        #[Assert\Uuid(strict: false)]
        public ?string $linkPreviewId = null,
    ) {}
}
