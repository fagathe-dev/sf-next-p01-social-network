<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Mail;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SupportReplyMailDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $recipientEmail,

        #[Assert\NotBlank]
        public string $recipientName,

        #[Assert\NotBlank]
        public string $reference,

        #[Assert\NotBlank]
        public string $subject,

        public string $excerpt,

        // Absent pour un visiteur, qui n'a pas de fil à consulter
        #[Assert\Url(requireTld: false)]
        public ?string $ticketUrl = null,
    ) {}
}
