<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Mail;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Lien de réinitialisation du mot de passe.
 */
final readonly class ForgotPasswordMailDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'L\'adresse e-mail du destinataire est obligatoire.')]
        #[Assert\Email(message: 'L\'adresse e-mail est invalide.')]
        public string $recipientEmail,

        #[Assert\NotBlank(message: 'Le nom du destinataire est obligatoire.')]
        public string $recipientName,

        #[Assert\NotBlank(message: 'Le lien de réinitialisation est obligatoire.')]
        #[Assert\Url(message: 'Le lien de réinitialisation est invalide.', requireTld: false)]
        public string $resetUrl,

        #[Assert\Positive]
        public int $ttlHours = 24,
    ) {}
}
