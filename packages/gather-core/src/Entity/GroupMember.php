<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Entity;

use Doctrine\ORM\Mapping as ORM;
use Fagathe\GatherCore\Enum\Social\GroupMemberStatus;
use Fagathe\GatherCore\Enum\Social\GroupRole;
use Fagathe\GatherCore\Repository\GroupMemberRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GroupMemberRepository::class)]
#[ORM\Table(name: 'group_member')]
#[ORM\UniqueConstraint(name: 'uniq_group_member', columns: ['group_id', 'user_id'])]
class GroupMember
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $joinedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'group_id', nullable: false, onDelete: 'CASCADE')]
        private SocialGroup $group,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,

        #[ORM\Column(length: 20, enumType: GroupRole::class)]
        private GroupRole $role = GroupRole::Member,

        #[ORM\Column(length: 20, enumType: GroupMemberStatus::class)]
        private GroupMemberStatus $status = GroupMemberStatus::Joined,
    ) {
        $this->id = Uuid::v7();
        if ($status === GroupMemberStatus::Joined) {
            $this->joinedAt = new \DateTimeImmutable();
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGroup(): SocialGroup
    {
        return $this->group;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): GroupRole
    {
        return $this->role;
    }

    public function setRole(GroupRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getStatus(): GroupMemberStatus
    {
        return $this->status;
    }

    public function isJoined(): bool
    {
        return $this->status === GroupMemberStatus::Joined;
    }

    public function canManage(): bool
    {
        return $this->isJoined() && $this->role !== GroupRole::Member;
    }

    public function approve(): static
    {
        $this->status = GroupMemberStatus::Joined;
        $this->joinedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getJoinedAt(): ?\DateTimeImmutable
    {
        return $this->joinedAt;
    }
}
