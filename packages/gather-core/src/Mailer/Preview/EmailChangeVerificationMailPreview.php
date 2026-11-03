<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Preview;

use Fagathe\GatherCore\Dto\Mail\EmailChangeVerificationMailDto;
use Fagathe\GatherCore\Generator\AuthLinkGenerator;
use Fagathe\GatherCore\Service\AccountService;

final class EmailChangeVerificationMailPreview implements MailPreviewInterface
{
    public function __construct(
        private readonly AuthLinkGenerator $linkGenerator,
    ) {}

    public static function getSlug(): string
    {
        return 'email-change-verification';
    }

    public function getLabel(): string
    {
        return 'Changement d\'adresse e-mail';
    }

    public function getDescription(): string
    {
        return 'Lien de confirmation envoyé à la nouvelle adresse.';
    }

    public function createDto(): object
    {
        return new EmailChangeVerificationMailDto(
            newEmail: self::RECIPIENT_EMAIL,
            recipientName: self::RECIPIENT_NAME,
            confirmationUrl: $this->linkGenerator->confirmEmailChangeUrl(bin2hex(random_bytes(32))),
            ttlHours: AccountService::EMAIL_CHANGE_TTL_HOURS,
        );
    }
}
