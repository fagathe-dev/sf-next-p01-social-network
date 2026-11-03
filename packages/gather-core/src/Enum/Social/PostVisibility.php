<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Social;

enum PostVisibility: string
{
    case Public = 'public';
    case Friends = 'friends';
    case Group = 'group';
}
