<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Auth;

enum UserStatus: string
{
    case Active = 'active';
    case ReadOnly = 'read_only';
    case Suspended = 'suspended';
    case Banned = 'banned';
}
