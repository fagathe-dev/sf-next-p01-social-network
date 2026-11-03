<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Handler;

use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use Fagathe\GatherCore\Dto\Mail\ModerationNoticeMailDto;
use Fagathe\GatherCore\Mailer\MailHandlerInterface;
use Symfony\Component\Mime\Address;

final class ModerationNoticeMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return ModerationNoticeMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@GatherCore/emails/moderation/notice.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return 'Information concernant votre compte Gather';
    }

    protected function getRecipients(object $dto): array
    {
        return [new Address($dto->recipientEmail, $dto->recipientName)];
    }
}
