<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Input;

use Fagathe\GatherCore\Enum\Support\TicketCategory;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SupportTicketInput
{
    public function __construct(
        public TicketCategory $category,

        #[Assert\NotBlank(message: 'Le sujet est obligatoire.')]
        #[Assert\Length(max: 180)]
        public string $subject,

        #[Assert\NotBlank(message: 'Le message est obligatoire.')]
        #[Assert\Length(max: 10000)]
        public string $message,
        #[Assert\Url(message: 'Cette adresse doit être une URL complète (http:// ou https://).', protocols: ['http', 'https'], requireTld: false)]
        #[Assert\Length(max: 2048)]
        public ?string $pageUrl = null,
    ) {}
}
