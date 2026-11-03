<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Handler;

use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use Fagathe\GatherCore\Dto\Mail\SupportReplyMailDto;
use Fagathe\GatherCore\Mailer\MailHandlerInterface;
use Symfony\Component\Mime\Address;

final class SupportReplyMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return SupportReplyMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@GatherCore/emails/support/reply.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return sprintf('Réponse à votre demande %s', $dto->reference);
    }

    protected function getRecipients(object $dto): array
    {
        return [new Address($dto->recipientEmail, $dto->recipientName)];
    }
}
