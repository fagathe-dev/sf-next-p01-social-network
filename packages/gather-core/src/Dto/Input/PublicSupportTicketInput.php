<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Input;

use Fagathe\GatherCore\Enum\Support\TicketCategory;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Demande d'un visiteur sans compte ou qui ne parvient pas à se connecter.
 */
final readonly class PublicSupportTicketInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'L\'adresse e-mail est obligatoire : elle sert à vous répondre.')]
        #[Assert\Email(message: 'Cette adresse e-mail est invalide.')]
        #[Assert\Length(max: 180)]
        public string $email,
        public TicketCategory $category,
        #[Assert\NotBlank(message: 'Le sujet est obligatoire.')]
        #[Assert\Length(max: 180)]
        public string $subject,
        #[Assert\NotBlank(message: 'Le message est obligatoire.')]
        #[Assert\Length(max: 10000)]
        public string $message,
        #[Assert\Length(max: 120)]
        public ?string $name = null,
        #[Assert\Url(message: 'Cette adresse doit être une URL complète (http:// ou https://).', protocols: ['http', 'https'], requireTld: false)]
        #[Assert\Length(max: 2048)]
        public ?string $pageUrl = null,
        // Piège à robots : champ invisible, jamais rempli par une personne
        public ?string $website = null,
    ) {}
}
