<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Common;

/**
 * Types MIME d'image acceptés pour les images affichées publiquement (avatar).
 *
 * Liste blanche sans image/svg+xml : un SVG peut embarquer du JavaScript (XSS stockée).
 */
final class SafeImageMimeTypes
{
    public const ALL = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];
}
