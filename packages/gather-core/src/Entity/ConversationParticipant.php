<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'conversation_participant')]
#[ORM\UniqueConstraint(name: 'uniq_conversation_participant', columns: ['conversation_id', 'user_id'])]
class ConversationParticipant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastReadAt = null;

    #[ORM\Column]
    private int $unreadCount = 0;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'participants')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Conversation $conversation,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
    ) {
        $this->id = Uuid::v7();
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLastReadAt(): ?\DateTimeImmutable
    {
        return $this->lastReadAt;
    }

    public function getUnreadCount(): int
    {
        return $this->unreadCount;
    }

    public function incrementUnread(): static
    {
        ++$this->unreadCount;

        return $this;
    }

    public function markRead(): static
    {
        $this->unreadCount = 0;
        $this->lastReadAt = new \DateTimeImmutable();

        return $this;
    }
}
