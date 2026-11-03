<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Mail;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Code à usage unique confirmant la suppression définitive du compte.
 */
final readonly class AccountDeletionCodeMailDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'L\'adresse e-mail du destinataire est obligatoire.')]
        #[Assert\Email(message: 'L\'adresse e-mail est invalide.')]
        public string $recipientEmail,

        #[Assert\NotBlank(message: 'Le nom du destinataire est obligatoire.')]
        public string $recipientName,

        #[Assert\NotBlank(message: 'Le code de confirmation est obligatoire.')]
        #[Assert\Regex(pattern: '/^\d{6}$/', message: 'Le code de confirmation doit contenir exactement 6 chiffres.')]
        public string $code,

        #[Assert\Positive]
        public int $ttlMinutes = 10,
    ) {}
}
