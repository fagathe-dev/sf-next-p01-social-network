<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Mail;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ModerationNoticeMailDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $recipientEmail,

        #[Assert\NotBlank]
        public string $recipientName,

        #[Assert\NotBlank]
        public string $action,

        #[Assert\NotBlank]
        public string $reason,

        public ?int $durationDays = null,
    ) {}
}
