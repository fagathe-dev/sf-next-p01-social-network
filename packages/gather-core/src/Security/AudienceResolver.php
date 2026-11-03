<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Security;

use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Repository\FriendshipRepository;
use Fagathe\GatherCore\Repository\GroupMemberRepository;
use Symfony\Bundle\SecurityBundle\Security;

final class AudienceResolver
{
    public function __construct(
        private readonly Security $security,
        private readonly FriendshipRepository $friendships,
        private readonly GroupMemberRepository $members,
    ) {}

    public function current(): Audience
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->for($user) : new Audience();
    }

    public function for(User $user): Audience
    {
        return new Audience($user, $this->friendships->friendIds($user), $this->members->joinedGroupIds($user));
    }
}
