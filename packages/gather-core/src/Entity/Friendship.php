<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Enum\Social\FriendshipStatus;
use Fagathe\GatherCore\Repository\FriendshipRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FriendshipRepository::class)]
#[ORM\Table(name: 'friendship')]
#[ORM\UniqueConstraint(name: 'uniq_friendship_pair', columns: ['requester_id', 'addressee_id'])]
class Friendship
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: FriendshipStatus::class)]
    private FriendshipStatus $status = FriendshipStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $requester,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $addressee,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRequester(): User
    {
        return $this->requester;
    }

    public function getAddressee(): User
    {
        return $this->addressee;
    }

    public function getStatus(): FriendshipStatus
    {
        return $this->status;
    }

    public function setStatus(FriendshipStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function otherThan(User $user): User
    {
        return $this->requester->getId()->equals($user->getId()) ? $this->addressee : $this->requester;
    }
}
