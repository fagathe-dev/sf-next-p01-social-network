<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Input;

use Fagathe\GatherCore\Enum\Moderation\ReportReason;
use Fagathe\GatherCore\Enum\Moderation\ReportTargetType;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReportInput
{
    public function __construct(
        public ReportTargetType $targetType,

        #[Assert\NotBlank]
        #[Assert\Uuid(strict: false)]
        public string $targetId,

        public ReportReason $reason,

        #[Assert\Length(max: 2000)]
        public ?string $details = null,
    ) {}
}
