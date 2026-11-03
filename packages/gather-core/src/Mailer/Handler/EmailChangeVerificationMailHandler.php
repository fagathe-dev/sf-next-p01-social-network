<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Handler;

use Fagathe\GatherCore\Dto\Mail\EmailChangeVerificationMailDto;
use Fagathe\GatherCore\Mailer\MailHandlerInterface;
use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use Symfony\Component\Mime\Address;

/**
 * @extends AbstractMailerHandler<EmailChangeVerificationMailDto>
 */
final class EmailChangeVerificationMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return EmailChangeVerificationMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@GatherCore/emails/auth/email_change_verification.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return 'Confirmez votre nouvelle adresse e-mail';
    }

    protected function getRecipients(object $dto): array
    {
        return [new Address($dto->newEmail, $dto->recipientName)];
    }
}