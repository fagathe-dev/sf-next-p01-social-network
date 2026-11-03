<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Output;

use Fagathe\GatherCore\Entity\User;

final readonly class UserSummaryOutput
{
    public function __construct(
        public string $id,
        public string $username,
        public ?string $displayName,
        public ?string $avatar,
    ) {}

    public static function from(User $user): self
    {
        return new self((string) $user->getId(), (string) $user->getUsername(), $user->getDisplayName(), $user->getAvatar());
    }
}
