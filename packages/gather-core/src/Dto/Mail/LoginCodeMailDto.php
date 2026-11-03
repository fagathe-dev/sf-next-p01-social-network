<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Mail;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Code à usage unique de la connexion sans mot de passe.
 */
final readonly class LoginCodeMailDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'L\'adresse e-mail du destinataire est obligatoire.')]
        #[Assert\Email(message: 'L\'adresse e-mail est invalide.')]
        public string $recipientEmail,

        #[Assert\NotBlank(message: 'Le nom du destinataire est obligatoire.')]
        public string $recipientName,

        #[Assert\NotBlank(message: 'Le code de connexion est obligatoire.')]
        #[Assert\Regex(pattern: '/^\d{6}$/', message: 'Le code de connexion doit contenir exactement 6 chiffres.')]
        public string $code,

        /** Durée de validité lisible (ex: "1 min 30") */
        #[Assert\NotBlank]
        public string $validityLabel,
    ) {}
}
