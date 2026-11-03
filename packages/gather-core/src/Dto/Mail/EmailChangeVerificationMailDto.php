<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Mail;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class EmailChangeVerificationMailDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'La nouvelle adresse e-mail est obligatoire.')]
        #[Assert\Email(message: 'La nouvelle adresse e-mail est invalide.')]
        public string $newEmail,

        #[Assert\NotBlank(message: 'Le nom d\'utilisateur est obligatoire.')]
        public string $recipientName,

        #[Assert\NotBlank(message: 'Le lien de confirmation est obligatoire.')]
        #[Assert\Url(message: 'Le lien de confirmation est invalide.', requireTld: false)]
        public string $confirmationUrl,

        #[Assert\Positive]
        public int $ttlHours = 24,
    ) {}
}