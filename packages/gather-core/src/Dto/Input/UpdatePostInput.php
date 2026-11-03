<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Input;

use Fagathe\GatherCore\Enum\Social\PostVisibility;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Modification d'une publication par son auteur : l'état complet est renvoyé, sauf l'audience,
 * laissée telle quelle si elle est absente (publication de communauté, republication).
 */
final readonly class UpdatePostInput
{
    /**
     * @param list<string> $removeAttachmentIds
     */
    public function __construct(
        #[Assert\Length(max: 20000, maxMessage: 'La publication ne peut pas dépasser {{ limit }} caractères.')]
        public string $content = '',

        #[Assert\Length(max: 255)]
        public ?string $title = null,

        public ?PostVisibility $visibility = null,

        #[Assert\Length(max: 255)]
        public ?string $locationName = null,

        #[Assert\Uuid(strict: false)]
        public ?string $linkPreviewId = null,

        #[Assert\All([new Assert\Uuid(strict: false)])]
        public array $removeAttachmentIds = [],
    ) {}
}
