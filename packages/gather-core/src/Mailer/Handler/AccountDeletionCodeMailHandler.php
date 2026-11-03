<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Handler;

use Fagathe\GatherCore\Dto\Mail\AccountDeletionCodeMailDto;
use Fagathe\GatherCore\Mailer\MailHandlerInterface;
use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use Symfony\Component\Mime\Address;

/**
 * @extends AbstractMailerHandler<AccountDeletionCodeMailDto>
 */
final class AccountDeletionCodeMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return AccountDeletionCodeMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@GatherCore/emails/auth/account_deletion_code.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return sprintf('Code de confirmation de suppression de compte : %s', $dto->code);
    }

    protected function getRecipients(object $dto): array
    {
        return [new Address($dto->recipientEmail, $dto->recipientName)];
    }
}
