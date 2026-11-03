<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Preview;

use Fagathe\GatherCore\Dto\Mail\LoginCodeMailDto;
use Fagathe\GatherCore\Service\AuthService;

final class LoginCodeMailPreview implements MailPreviewInterface
{
    public static function getSlug(): string
    {
        return 'login-code';
    }

    public function getLabel(): string
    {
        return 'Code de connexion';
    }

    public function getDescription(): string
    {
        return 'Code à 6 chiffres (1 min 30) pour la connexion sans mot de passe.';
    }

    public function createDto(): object
    {
        return new LoginCodeMailDto(
            recipientEmail: self::RECIPIENT_EMAIL,
            recipientName: self::RECIPIENT_NAME,
            code: (string) random_int(100000, 999999),
            validityLabel: AuthService::LOGIN_CODE_VALIDITY_LABEL,
        );
    }
}
