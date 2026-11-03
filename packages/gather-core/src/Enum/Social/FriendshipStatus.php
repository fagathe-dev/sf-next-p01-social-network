<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Social;

enum FriendshipStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Blocked = 'blocked';
}
