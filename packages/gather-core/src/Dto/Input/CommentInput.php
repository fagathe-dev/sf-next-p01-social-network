<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Input;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CommentInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le commentaire est vide.')]
        #[Assert\Length(max: 5000, maxMessage: 'Le commentaire ne peut pas dépasser {{ limit }} caractères.')]
        public string $content = '',

        #[Assert\Uuid(strict: false)]
        public ?string $parentId = null,
    ) {}
}
