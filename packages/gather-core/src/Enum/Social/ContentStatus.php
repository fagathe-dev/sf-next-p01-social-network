<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Social;

enum ContentStatus: string
{
    case Published = 'published';
    case AutoHidden = 'auto_hidden';
    case Deleted = 'deleted';
}
