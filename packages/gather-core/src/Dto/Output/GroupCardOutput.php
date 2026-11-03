<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Output;

use Fagathe\GatherCore\Entity\SocialGroup;

final readonly class GroupCardOutput
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $description,
        public string $themePalette,
        public ?string $iconName,
        public string $visibility,
        public int $membersCount,
    ) {}

    public static function from(SocialGroup $group): self
    {
        return new self(
            (string) $group->getId(),
            $group->getName(),
            $group->getSlug(),
            $group->getDescription(),
            $group->getThemePalette()->value,
            $group->getIconName(),
            $group->getVisibility()->value,
            $group->getMembersCount(),
        );
    }
}
