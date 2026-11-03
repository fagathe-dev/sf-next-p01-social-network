<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Social;

enum GroupMemberStatus: string
{
    case Pending = 'pending';
    case Joined = 'joined';
}
