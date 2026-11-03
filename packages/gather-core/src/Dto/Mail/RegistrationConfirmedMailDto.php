<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Mail;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * E-mail de bienvenue envoyé une fois le compte vérifié.
 */
final readonly class RegistrationConfirmedMailDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'L\'adresse e-mail du destinataire est obligatoire.')]
        #[Assert\Email(message: 'L\'adresse e-mail est invalide.')]
        public string $recipientEmail,

        #[Assert\NotBlank(message: 'Le nom du destinataire est obligatoire.')]
        public string $recipientName,

        #[Assert\NotBlank(message: 'L\'URL de connexion est obligatoire.')]
        #[Assert\Url(message: 'L\'URL de connexion est invalide.', requireTld: false)]
        public string $loginUrl,
    ) {}
}
