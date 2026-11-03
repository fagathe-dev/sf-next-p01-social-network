<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Preview;

use Fagathe\GatherCore\Dto\Mail\NewMessageMailDto;
use Fagathe\GatherCore\Generator\AuthLinkGenerator;

final class NewMessageMailPreview implements MailPreviewInterface
{
    public function __construct(
        private readonly AuthLinkGenerator $links,
    ) {}

    public static function getSlug(): string
    {
        return 'new-message';
    }

    public function getLabel(): string
    {
        return 'Nouveau message';
    }

    public function getDescription(): string
    {
        return 'Notification du premier message non lu d\'une conversation.';
    }

    public function createDto(): object
    {
        return new NewMessageMailDto(
            recipientEmail: self::RECIPIENT_EMAIL,
            recipientName: self::RECIPIENT_NAME,
            senderName: 'Clara Martin',
            excerpt: 'Tu viens à la sortie photo samedi ?',
            conversationUrl: $this->links->conversationUrl('00000000-0000-7000-8000-000000000000'),
        );
    }
}
