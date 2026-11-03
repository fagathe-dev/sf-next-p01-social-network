<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Social;

enum AttachmentType: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
}
