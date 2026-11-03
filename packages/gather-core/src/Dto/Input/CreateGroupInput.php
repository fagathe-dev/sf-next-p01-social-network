<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Input;

use Fagathe\GatherCore\Enum\Social\GroupVisibility;
use Fagathe\GatherCore\Enum\Social\ThemePalette;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateGroupInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le nom de la communauté est obligatoire.')]
        #[Assert\Length(min: 3, max: 120)]
        public string $name = '',

        #[Assert\Length(max: 2000)]
        public ?string $description = null,

        public ThemePalette $themePalette = ThemePalette::Indigo,

        #[Assert\Length(max: 60)]
        #[Assert\Regex(pattern: '/^[a-z0-9-]+$/', message: 'Nom d\'icône invalide.')]
        public ?string $iconName = null,

        public GroupVisibility $visibility = GroupVisibility::Public,
    ) {}
}
