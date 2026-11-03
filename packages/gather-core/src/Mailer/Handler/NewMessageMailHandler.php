<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Handler;

use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use Fagathe\GatherCore\Dto\Mail\NewMessageMailDto;
use Fagathe\GatherCore\Mailer\MailHandlerInterface;
use Symfony\Component\Mime\Address;

final class NewMessageMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return NewMessageMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@GatherCore/emails/social/new_message.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return sprintf('Nouveau message de %s', $dto->senderName);
    }

    protected function getRecipients(object $dto): array
    {
        return [new Address($dto->recipientEmail, $dto->recipientName)];
    }
}
