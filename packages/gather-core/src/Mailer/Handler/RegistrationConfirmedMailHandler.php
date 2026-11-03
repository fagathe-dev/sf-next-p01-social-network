<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Handler;

use Fagathe\GatherCore\Dto\Mail\RegistrationConfirmedMailDto;
use Fagathe\GatherCore\Mailer\MailHandlerInterface;
use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use Symfony\Component\Mime\Address;

/**
 * @extends AbstractMailerHandler<RegistrationConfirmedMailDto>
 */
final class RegistrationConfirmedMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return RegistrationConfirmedMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@GatherCore/emails/auth/registration_confirmed.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return 'Bienvenue ! Votre compte est maintenant activé';
    }

    protected function getRecipients(object $dto): array
    {
        return [new Address($dto->recipientEmail, $dto->recipientName)];
    }
}
