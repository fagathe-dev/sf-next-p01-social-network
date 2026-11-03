<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Enum\Moderation\ModerationActionType;
use Fagathe\GatherCore\Repository\ModerationLogRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ModerationLogRepository::class)]
#[ORM\Table(name: 'moderation_log')]
#[ORM\Index(name: 'idx_moderation_log_date', columns: ['created_at'])]
class ModerationLog
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private User $moderator,

        #[ORM\Column(length: 30, enumType: ModerationActionType::class)]
        private ModerationActionType $actionType,

        #[ORM\Column(length: 1000)]
        private string $reason,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $targetUser = null,

        #[ORM\Column(nullable: true)]
        private ?int $durationDays = null,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getModerator(): User
    {
        return $this->moderator;
    }

    public function getTargetUser(): ?User
    {
        return $this->targetUser;
    }

    public function getActionType(): ModerationActionType
    {
        return $this->actionType;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getDurationDays(): ?int
    {
        return $this->durationDays;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
