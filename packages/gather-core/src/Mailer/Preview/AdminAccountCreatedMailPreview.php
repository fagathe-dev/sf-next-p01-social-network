<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Preview;

use Fagathe\GatherCore\Dto\Mail\AdminAccountCreatedMailDto;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Fagathe\GatherCore\Generator\AuthLinkGenerator;
use Fagathe\GatherCore\Security\AdminUserManager;
use Fagathe\CorePhp\Trait\DatetimeTrait;

final class AdminAccountCreatedMailPreview implements MailPreviewInterface
{
    use DatetimeTrait;

    public function __construct(
        private readonly AuthLinkGenerator $linkGenerator,
    ) {}

    public static function getSlug(): string
    {
        return 'admin-account-created';
    }

    public function getLabel(): string
    {
        return 'Compte administrateur créé';
    }

    public function getDescription(): string
    {
        return 'Récapitulatif et lien de changement de mot de passe (core-php:create-admin-user).';
    }

    public function createDto(): object
    {
        return new AdminAccountCreatedMailDto(
            recipientEmail: self::RECIPIENT_EMAIL,
            recipientName: self::RECIPIENT_NAME,
            username: 'jane.admin',
            // Mot de passe factice : l'aperçu n'expose jamais de vraie donnée
            plainPassword: 'MotDePasse123',
            roleLabel: UserRole::mapRole(UserRole::ROLE_ADMIN),
            loginUrl: $this->linkGenerator->loginUrl(),
            changePasswordUrl: $this->linkGenerator->resetPasswordUrl(bin2hex(random_bytes(32))),
            createdAt: $this->now(),
            ttlHours: AdminUserManager::PASSWORD_LINK_TTL_HOURS,
        );
    }
}
