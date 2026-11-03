<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Mail;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Récapitulatif envoyé à un administrateur créé (commande core-php:create-admin-user).
 *
 * ⚠️ Contient le mot de passe en clair : il n'apparaît que dans l'e-mail (jamais dans les logs du mailer).
 */
final readonly class AdminAccountCreatedMailDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'L\'adresse e-mail du destinataire est obligatoire.')]
        #[Assert\Email(message: 'L\'adresse e-mail est invalide.')]
        public string $recipientEmail,

        #[Assert\NotBlank(message: 'Le nom du destinataire est obligatoire.')]
        public string $recipientName,

        #[Assert\NotBlank(message: 'Le nom d\'utilisateur est obligatoire.')]
        public string $username,

        #[Assert\NotBlank(message: 'Le mot de passe est obligatoire.')]
        public string $plainPassword,

        #[Assert\NotBlank(message: 'Le rôle est obligatoire.')]
        public string $roleLabel,

        #[Assert\NotBlank(message: 'L\'URL de connexion est obligatoire.')]
        #[Assert\Url(message: 'L\'URL de connexion est invalide.', requireTld: false)]
        public string $loginUrl,

        #[Assert\NotBlank(message: 'Le lien de changement de mot de passe est obligatoire.')]
        #[Assert\Url(message: 'Le lien de changement de mot de passe est invalide.', requireTld: false)]
        public string $changePasswordUrl,

        public \DateTimeImmutable $createdAt,

        #[Assert\Positive]
        public int $ttlHours = 72,

        /** Origine de la création : "command" (ligne de commande) ou "dashboard" */
        #[Assert\Choice(choices: ['command', 'dashboard'])]
        public string $createdBy = 'command',
    ) {}
}
