<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Support;

enum TicketCategory: string
{
    case BugReport = 'bug_report';
    case AccountIssue = 'account_issue';
    case ModerationAppeal = 'moderation_appeal';
    case PrivacyGdpr = 'privacy_gdpr';
    case Other = 'other';
}
