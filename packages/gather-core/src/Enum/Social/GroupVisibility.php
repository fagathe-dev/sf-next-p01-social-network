<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Social;

enum GroupVisibility: string
{
    case Public = 'public';
    case Private = 'private';
}
