<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Preview;

use Fagathe\GatherCore\Dto\Mail\SupportReplyMailDto;
use Fagathe\GatherCore\Generator\AuthLinkGenerator;

final class SupportReplyMailPreview implements MailPreviewInterface
{
    public function __construct(
        private readonly AuthLinkGenerator $links,
    ) {}

    public static function getSlug(): string
    {
        return 'support-reply';
    }

    public function getLabel(): string
    {
        return 'Suivi de ticket support';
    }

    public function getDescription(): string
    {
        return 'Réponse de l\'équipe support à un ticket.';
    }

    public function createDto(): object
    {
        return new SupportReplyMailDto(
            recipientEmail: self::RECIPIENT_EMAIL,
            recipientName: self::RECIPIENT_NAME,
            reference: '#TCK-2026-0042',
            subject: 'Je ne peux plus publier',
            excerpt: 'Bonjour, nous avons vérifié votre compte : tout est de nouveau fonctionnel.',
            ticketUrl: $this->links->ticketUrl('00000000-0000-7000-8000-000000000000'),
        );
    }
}
