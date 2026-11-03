<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Security;

use Fagathe\GatherCore\Entity\Post;
use Fagathe\GatherCore\Entity\SocialGroup;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Fagathe\GatherCore\Enum\Auth\UserStatus;
use Fagathe\GatherCore\Enum\Social\PostVisibility;
use Symfony\Component\Uid\Uuid;

/**
 * Ce qu'un visiteur a le droit de voir : source unique des règles de confidentialité (section 2.6).
 * PostRepository::applyVisibility() en est la traduction SQL : toute évolution se fait aux deux endroits.
 */
final readonly class Audience
{
    /**
     * @param list<Uuid> $friendIds
     * @param list<Uuid> $groupIds groupes dont le visiteur est membre validé
     */
    public function __construct(
        public ?User $user = null,
        public array $friendIds = [],
        public array $groupIds = [],
    ) {}

    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    public function isStaff(): bool
    {
        return $this->user !== null && in_array($this->user->getRole(), [UserRole::ROLE_MODERATOR, UserRole::ROLE_ADMIN], true);
    }

    public function isFriendOf(User $other): bool
    {
        return self::contains($this->friendIds, $other->getId());
    }

    public function isMemberOf(SocialGroup $group): bool
    {
        return self::contains($this->groupIds, $group->getId());
    }

    public function isSelf(User $other): bool
    {
        return $this->user !== null && $this->user->getId()->equals($other->getId());
    }

    public function canSeeGroup(SocialGroup $group): bool
    {
        return $group->isPublic() || $this->isMemberOf($group) || $this->isStaff();
    }

    public function canViewPost(Post $post): bool
    {
        if ($this->isStaff() || $this->isSelf($post->getAuthor())) {
            return true;
        }

        if (!$post->isPublished() || !$post->getAuthor()->isVisible()) {
            return false;
        }

        $group = $post->getGroup();
        if ($group !== null) {
            return $this->canSeeGroup($group);
        }

        if ($post->getVisibility() === PostVisibility::Friends) {
            return $this->isFriendOf($post->getAuthor());
        }

        // Un compte privé réserve ses publications hors groupe à ses amis
        return !$post->getAuthor()->isPrivate() || $this->isFriendOf($post->getAuthor());
    }

    /** Liker, commenter : groupe public ouvert à tout connecté, groupe privé réservé aux membres. */
    public function canEngage(Post $post): bool
    {
        if ($this->user === null || !$this->canWrite() || !$this->canViewPost($post)) {
            return false;
        }

        $group = $post->getGroup();

        return $group === null || $group->isPublic() || $this->isMemberOf($group);
    }

    /** Une sanction « lecture seule » retire tout droit d'écriture. */
    public function canWrite(): bool
    {
        return $this->user !== null && $this->user->getEffectiveStatus() === UserStatus::Active;
    }

    /**
     * @param list<Uuid> $ids
     */
    private static function contains(array $ids, Uuid $id): bool
    {
        foreach ($ids as $candidate) {
            if ($candidate->equals($id)) {
                return true;
            }
        }

        return false;
    }
}
