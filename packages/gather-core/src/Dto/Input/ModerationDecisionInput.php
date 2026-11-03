<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Input;

use Fagathe\GatherCore\Enum\Moderation\ModerationActionType;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ModerationDecisionInput
{
    public function __construct(
        public ModerationActionType $action,

        #[Assert\NotBlank(message: 'Le motif est obligatoire.')]
        #[Assert\Length(max: 1000)]
        public string $reason,

        // Lecture seule : 1 à 3 jours. Suspension : 7 à 30 jours.
        #[Assert\Positive]
        public ?int $durationDays = null,
    ) {}
}
