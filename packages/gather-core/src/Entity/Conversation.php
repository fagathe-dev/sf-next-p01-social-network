<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Repository\ConversationRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversation')]
class Conversation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ConversationParticipant> */
    #[ORM\OneToMany(targetEntity: ConversationParticipant::class, mappedBy: 'conversation', cascade: ['persist'], orphanRemoval: true)]
    private Collection $participants;

    public function __construct(
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $title = null,
    ) {
        $this->id = Uuid::v7();
        $this->updatedAt = new \DateTimeImmutable();
        $this->participants = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
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

    /** @return Collection<int, ConversationParticipant> */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function addParticipant(User $user): static
    {
        $this->participants->add(new ConversationParticipant($this, $user));

        return $this;
    }

    public function participantFor(User $user): ?ConversationParticipant
    {
        foreach ($this->participants as $participant) {
            if ($participant->getUser()->getId()->equals($user->getId())) {
                return $participant;
            }
        }

        return null;
    }
}
