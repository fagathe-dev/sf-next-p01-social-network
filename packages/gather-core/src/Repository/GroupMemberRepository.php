<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\GatherCore\Entity\GroupMember;
use Fagathe\GatherCore\Entity\SocialGroup;
use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Social\GroupMemberStatus;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<GroupMember>
 */
class GroupMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupMember::class);
    }

    public function findMembership(SocialGroup $group, User $user): ?GroupMember
    {
        return $this->findOneBy(['group' => $group, 'user' => $user]);
    }

    /** @return list<Uuid> */
    public function joinedGroupIds(User $user): array
    {
        return array_map(
            static fn(GroupMember $member) => $member->getGroup()->getId(),
            $this->createQueryBuilder('m')
                ->addSelect('g')
                ->innerJoin('m.group', 'g')
                ->where('m.user = :user AND m.status = :status')
                ->setParameter('user', $user->getId(), UuidType::NAME)
                ->setParameter('status', GroupMemberStatus::Joined)
                ->getQuery()
                ->getResult()
        );
    }

    /** @return list<GroupMember> */
    public function listByStatus(SocialGroup $group, GroupMemberStatus $status, int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('u')
            ->innerJoin('m.user', 'u')
            ->where('m.group = :group AND m.status = :status AND u.deactivatedAt IS NULL')
            ->setParameter('group', $group->getId(), UuidType::NAME)
            ->setParameter('status', $status)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countJoined(SocialGroup $group): int
    {
        return $this->count(['group' => $group, 'status' => GroupMemberStatus::Joined]);
    }
}
