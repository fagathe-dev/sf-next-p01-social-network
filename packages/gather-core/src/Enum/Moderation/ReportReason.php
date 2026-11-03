<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Moderation;

enum ReportReason: string
{
    case Spam = 'spam';
    case Harassment = 'harassment';
    case HateSpeech = 'hate_speech';
    case Violence = 'violence';
    case Nudity = 'nudity';
    case Misinformation = 'misinformation';
    case Other = 'other';
}
