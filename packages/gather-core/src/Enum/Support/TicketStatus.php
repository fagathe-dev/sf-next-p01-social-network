<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Support;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingUser = 'waiting_user';
    case Resolved = 'resolved';
}
