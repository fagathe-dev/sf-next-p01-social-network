<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer\Preview;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Aperçu d'un e-mail avec des données factices (aucun envoi), découvert automatiquement par EmailPreviewService.
 *
 * Ajouter un aperçu = une classe implémentant cette interface : EmailPreviewService n'est pas modifié.
 */
#[AutoconfigureTag(self::TAG)]
interface MailPreviewInterface
{
    public const TAG = 'gather_core.mail_preview';

    public const RECIPIENT_EMAIL = 'jane.doe@example.com';
    public const RECIPIENT_NAME = 'Jane Doe';

    /**
     * Identifiant d'URL (kebab-case), ex. "login-code".
     */
    public static function getSlug(): string;

    public function getLabel(): string;

    public function getDescription(): string;

    /**
     * DTO factice transmis au handler de l'e-mail (Fagathe\GatherCore\Mailer\Handler\*).
     */
    public function createDto(): object;
}
