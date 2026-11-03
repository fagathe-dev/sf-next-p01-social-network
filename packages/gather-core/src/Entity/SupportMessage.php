<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'support_message')]
class SupportMessage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private SupportTicket $ticket,

        // Null : message d'un visiteur, sans compte
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true)]
        private ?User $sender,

        #[ORM\Column(type: Types::TEXT)]
        private string $content,

        #[ORM\Column]
        private bool $isInternalNote = false,

        /** @var list<array{path: string, name: string, mimeType: string, size: int}>|null */
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $attachments = null,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTicket(): SupportTicket
    {
        return $this->ticket;
    }

    public function getSender(): ?User
    {
        return $this->sender;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function isInternalNote(): bool
    {
        return $this->isInternalNote;
    }

    /**
     * @return list<array{path: string, name: string, mimeType: string, size: int}>
     */
    public function getAttachments(): array
    {
        return $this->attachments ?? [];
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
