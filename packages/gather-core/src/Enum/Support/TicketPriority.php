<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Support;

enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
