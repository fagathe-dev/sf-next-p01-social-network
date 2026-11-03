<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Mail;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class NewMessageMailDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $recipientEmail,

        #[Assert\NotBlank]
        public string $recipientName,

        #[Assert\NotBlank]
        public string $senderName,

        public string $excerpt,

        #[Assert\NotBlank]
        #[Assert\Url(requireTld: false)]
        public string $conversationUrl,
    ) {}
}
