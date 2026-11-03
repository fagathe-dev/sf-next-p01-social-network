<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Handler;

use Fagathe\GatherCore\Dto\Mail\AdminAccountCreatedMailDto;
use Fagathe\GatherCore\Mailer\MailHandlerInterface;
use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use Symfony\Component\Mime\Address;

/**
 * @extends AbstractMailerHandler<AdminAccountCreatedMailDto>
 */
final class AdminAccountCreatedMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return AdminAccountCreatedMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@GatherCore/emails/admin/account_created.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return 'Votre compte administrateur a été créé';
    }

    protected function getRecipients(object $dto): array
    {
        return [new Address($dto->recipientEmail, $dto->recipientName)];
    }
}
