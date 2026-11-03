<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Mailer;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Handler d'e-mail (Fagathe\CorePhp\Mailer\AbstractMailerHandler) découvert automatiquement par MailDispatcher.
 *
 * Ajouter un e-mail = un DTO + un handler implémentant cette interface : MailDispatcher n'est pas modifié.
 */
#[AutoconfigureTag(self::TAG)]
interface MailHandlerInterface
{
    public const TAG = 'gather_core.mail_handler';

    /**
     * Classe du DTO pris en charge (clé du locator de MailDispatcher).
     *
     * @return class-string
     */
    public static function getDtoClass(): string;
}
