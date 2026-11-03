<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Mail;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Lien de vérification envoyé à l'inscription (connexion automatique au clic).
 */
final readonly class AccountVerificationMailDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'L\'adresse e-mail du destinataire est obligatoire.')]
        #[Assert\Email(message: 'L\'adresse e-mail est invalide.')]
        public string $recipientEmail,

        #[Assert\NotBlank(message: 'Le nom du destinataire est obligatoire.')]
        public string $recipientName,

        #[Assert\NotBlank(message: 'Le lien de validation est obligatoire.')]
        #[Assert\Url(message: 'Le lien de validation est invalide.', requireTld: false)]
        public string $verificationUrl,

        #[Assert\Positive]
        public int $ttlHours = 72,
    ) {}
}
