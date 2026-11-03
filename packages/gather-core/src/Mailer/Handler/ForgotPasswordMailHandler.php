<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Handler;

use Fagathe\GatherCore\Dto\Mail\ForgotPasswordMailDto;
use Fagathe\GatherCore\Mailer\MailHandlerInterface;
use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use Symfony\Component\Mime\Address;

/**
 * @extends AbstractMailerHandler<ForgotPasswordMailDto>
 */
final class ForgotPasswordMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return ForgotPasswordMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@GatherCore/emails/auth/forgot_password.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return 'Réinitialisation de votre mot de passe';
    }

    protected function getRecipients(object $dto): array
    {
        return [new Address($dto->recipientEmail, $dto->recipientName)];
    }
}
