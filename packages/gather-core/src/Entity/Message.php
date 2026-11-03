<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Repository\MessageRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'message')]
#[ORM\Index(name: 'idx_message_conversation', columns: ['conversation_id', 'created_at'])]
class Message
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Conversation $conversation,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private User $sender,

        #[ORM\Column(type: Types::TEXT)]
        private string $content,

        #[ORM\Column(length: 512, nullable: true)]
        private ?string $attachmentPath = null,

        #[ORM\Column(length: 255, nullable: true)]
        private ?string $attachmentName = null,

        #[ORM\Column(length: 100, nullable: true)]
        private ?string $attachmentMimeType = null,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getSender(): User
    {
        return $this->sender;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getAttachmentName(): ?string
    {
        return $this->attachmentName;
    }

    public function getAttachmentMimeType(): ?string
    {
        return $this->attachmentMimeType;
    }

    public function getAttachmentPath(): ?string
    {
        return $this->attachmentPath;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
