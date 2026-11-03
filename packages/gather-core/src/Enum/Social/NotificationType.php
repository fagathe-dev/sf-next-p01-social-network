<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Social;

enum NotificationType: string
{
    case FriendRequestReceived = 'friend_request_received';
    case FriendRequestAccepted = 'friend_request_accepted';
    case PostCommented = 'post_commented';
    case CommentReplied = 'comment_replied';
    case CommentLiked = 'comment_liked';
    case PostLiked = 'post_liked';
    case PostReposted = 'post_reposted';
    case UserMentioned = 'user_mentioned';
    case GroupInvitation = 'group_invitation';
    case GroupJoinApproved = 'group_join_approved';
    case SupportTicketReply = 'support_ticket_reply';
}
