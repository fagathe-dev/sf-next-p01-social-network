<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Preview;

use Fagathe\GatherCore\Dto\Mail\GroupInvitationMailDto;
use Fagathe\GatherCore\Generator\AuthLinkGenerator;

final class GroupInvitationMailPreview implements MailPreviewInterface
{
    public function __construct(
        private readonly AuthLinkGenerator $links,
    ) {}

    public static function getSlug(): string
    {
        return 'group-invitation';
    }

    public function getLabel(): string
    {
        return 'Invitation à une communauté';
    }

    public function getDescription(): string
    {
        return 'Lien d\'invitation envoyé par un membre.';
    }

    public function createDto(): object
    {
        return new GroupInvitationMailDto(
            recipientEmail: self::RECIPIENT_EMAIL,
            recipientName: self::RECIPIENT_NAME,
            inviterName: 'Clara Martin',
            groupName: 'Photographie',
            groupUrl: $this->links->groupUrl('photographie'),
        );
    }
}
