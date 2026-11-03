<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Moderation;

enum ReportStatus: string
{
    case Pending = 'pending';
    case AutoHidden = 'auto_hidden';
    case Resolved = 'resolved';
}
