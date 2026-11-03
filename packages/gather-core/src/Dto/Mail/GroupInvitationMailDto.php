<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Mail;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class GroupInvitationMailDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $recipientEmail,

        #[Assert\NotBlank]
        public string $recipientName,

        #[Assert\NotBlank]
        public string $inviterName,

        #[Assert\NotBlank]
        public string $groupName,

        #[Assert\NotBlank]
        #[Assert\Url(requireTld: false)]
        public string $groupUrl,
    ) {}
}
