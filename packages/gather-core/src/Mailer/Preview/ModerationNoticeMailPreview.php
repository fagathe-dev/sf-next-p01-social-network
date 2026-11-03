<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Preview;

use Fagathe\GatherCore\Dto\Mail\ModerationNoticeMailDto;
use Fagathe\GatherCore\Enum\Moderation\ModerationActionType;

final class ModerationNoticeMailPreview implements MailPreviewInterface
{
    public static function getSlug(): string
    {
        return 'moderation-notice';
    }

    public function getLabel(): string
    {
        return 'Avertissement de modération';
    }

    public function getDescription(): string
    {
        return 'Décision de modération notifiée à l\'auteur du contenu.';
    }

    public function createDto(): object
    {
        return new ModerationNoticeMailDto(
            recipientEmail: self::RECIPIENT_EMAIL,
            recipientName: self::RECIPIENT_NAME,
            action: ModerationActionType::ReadOnly->value,
            reason: 'Propos irrespectueux répétés dans les commentaires.',
            durationDays: 3,
        );
    }
}
