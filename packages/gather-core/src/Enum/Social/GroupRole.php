<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Social;

enum GroupRole: string
{
    case Owner = 'owner';
    case Moderator = 'moderator';
    case Member = 'member';
}
