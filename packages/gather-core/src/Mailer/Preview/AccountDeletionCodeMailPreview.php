<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Preview;

use Fagathe\GatherCore\Dto\Mail\AccountDeletionCodeMailDto;
use Fagathe\GatherCore\Service\AccountService;

final class AccountDeletionCodeMailPreview implements MailPreviewInterface
{
    public static function getSlug(): string
    {
        return 'account-deletion-code';
    }

    public function getLabel(): string
    {
        return 'Suppression de compte';
    }

    public function getDescription(): string
    {
        return 'Code à 6 chiffres (10 min) confirmant la suppression définitive du compte.';
    }

    public function createDto(): object
    {
        return new AccountDeletionCodeMailDto(
            recipientEmail: self::RECIPIENT_EMAIL,
            recipientName: self::RECIPIENT_NAME,
            code: (string) random_int(100000, 999999),
            ttlMinutes: AccountService::DELETION_CODE_TTL_MINUTES,
        );
    }
}
