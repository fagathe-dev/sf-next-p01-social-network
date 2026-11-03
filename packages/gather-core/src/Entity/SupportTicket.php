<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Enum\Support\TicketCategory;
use Fagathe\GatherCore\Enum\Support\TicketPriority;
use Fagathe\GatherCore\Enum\Support\TicketStatus;
use Fagathe\GatherCore\Repository\SupportTicketRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SupportTicketRepository::class)]
#[ORM\Table(name: 'support_ticket')]
#[ORM\UniqueConstraint(name: 'uniq_ticket_reference', columns: ['reference'])]
class SupportTicket
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    #[ORM\Column(length: 20, enumType: TicketStatus::class)]
    private TicketStatus $status = TicketStatus::Open;

    #[ORM\Column(length: 20, enumType: TicketPriority::class)]
    private TicketPriority $priority = TicketPriority::Normal;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $guestEmail = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $guestName = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $pageUrl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(length: 20)]
        private string $reference,

        // Null pour une demande de visiteur : voir guestEmail
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?User $user,

        #[ORM\Column(length: 30, enumType: TicketCategory::class)]
        private TicketCategory $category,

        #[ORM\Column(length: 180)]
        private string $subject,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function isGuest(): bool
    {
        return $this->user === null;
    }

    public function setGuest(string $email, ?string $name): static
    {
        $this->guestEmail = $email;
        $this->guestName = $name;

        return $this;
    }

    public function getGuestEmail(): ?string
    {
        return $this->guestEmail;
    }

    public function getGuestName(): ?string
    {
        return $this->guestName;
    }

    public function getRequesterEmail(): string
    {
        return (string) ($this->user?->getEmail() ?? $this->guestEmail);
    }

    public function getRequesterName(): string
    {
        return (string) ($this->user?->getDisplayName() ?? $this->guestName ?? $this->guestEmail);
    }

    public function getPageUrl(): ?string
    {
        return $this->pageUrl;
    }

    public function setPageUrl(?string $pageUrl): static
    {
        $this->pageUrl = $pageUrl;

        return $this;
    }

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;

        return $this;
    }

    public function getCategory(): TicketCategory
    {
        return $this->category;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getStatus(): TicketStatus
    {
        return $this->status;
    }

    public function setStatus(TicketStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPriority(): TicketPriority
    {
        return $this->priority;
    }

    public function setPriority(TicketPriority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
