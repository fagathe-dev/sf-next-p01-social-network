<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Handler;

use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use Fagathe\GatherCore\Dto\Mail\GroupInvitationMailDto;
use Fagathe\GatherCore\Mailer\MailHandlerInterface;
use Symfony\Component\Mime\Address;

final class GroupInvitationMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return GroupInvitationMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@GatherCore/emails/social/group_invitation.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return sprintf('%s vous invite à rejoindre %s', $dto->inviterName, $dto->groupName);
    }

    protected function getRecipients(object $dto): array
    {
        return [new Address($dto->recipientEmail)];
    }
}
