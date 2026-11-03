<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Preview;

use Fagathe\GatherCore\Dto\Mail\AccountVerificationMailDto;
use Fagathe\GatherCore\Generator\AuthLinkGenerator;
use Fagathe\GatherCore\Service\AuthService;

final class AccountVerificationMailPreview implements MailPreviewInterface
{
    public function __construct(
        private readonly AuthLinkGenerator $linkGenerator,
    ) {}

    public static function getSlug(): string
    {
        return 'account-verification';
    }

    public function getLabel(): string
    {
        return 'Vérification du compte';
    }

    public function getDescription(): string
    {
        return 'Lien d\'activation envoyé à l\'inscription.';
    }

    public function createDto(): object
    {
        return new AccountVerificationMailDto(
            recipientEmail: self::RECIPIENT_EMAIL,
            recipientName: self::RECIPIENT_NAME,
            verificationUrl: $this->linkGenerator->verifyEmailUrl(bin2hex(random_bytes(32))),
            ttlHours: AuthService::VERIFICATION_TTL_HOURS,
        );
    }
}
