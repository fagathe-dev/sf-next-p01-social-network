<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Enum\Social\NotificationType;
use Fagathe\GatherCore\Repository\NotificationRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une notification regroupe les actions semblables encore non lues (« Sam et 3 autres ont aimé… ») :
 * lastActor est le plus récent, actorsCount le nombre de personnes.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'idx_notification_recipient', columns: ['recipient_id', 'read_at', 'created_at'])]
#[ORM\Index(name: 'idx_notification_recent', columns: ['recipient_id', 'updated_at'])]
class Notification
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $lastActor;

    #[ORM\Column]
    private int $actorsCount = 1;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $recipient,

        ?User $actor,

        #[ORM\Column(length: 40, enumType: NotificationType::class)]
        private NotificationType $type,

        #[ORM\Column(length: 20)]
        private string $targetType,

        #[ORM\Column(length: 36)]
        private string $targetId,
    ) {
        $this->id = Uuid::v7();
        $this->lastActor = $actor;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    /** Une personne de plus : elle devient la plus récente, sans compter deux fois la même d'affilée. */
    public function addActor(?User $actor): static
    {
        if ($actor !== null && ($this->lastActor === null || !$this->lastActor->getId()->equals($actor->getId()))) {
            ++$this->actorsCount;
            $this->lastActor = $actor;
        }
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markRead(): static
    {
        $this->readAt ??= new \DateTimeImmutable();

        return $this;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getLastActor(): ?User
    {
        return $this->lastActor;
    }

    public function getActorsCount(): int
    {
        return $this->actorsCount;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
