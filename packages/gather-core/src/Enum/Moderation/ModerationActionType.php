<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Moderation;

enum ModerationActionType: string
{
    case Dismiss = 'dismiss';
    case DeleteContent = 'delete_content';
    case Warning = 'warning';
    case ReadOnly = 'read_only';
    case Suspension = 'suspension';
    case Ban = 'ban';
}
