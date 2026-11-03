<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Moderation;

enum ReportTargetType: string
{
    case Post = 'post';
    case Comment = 'comment';
    case User = 'user';
}
