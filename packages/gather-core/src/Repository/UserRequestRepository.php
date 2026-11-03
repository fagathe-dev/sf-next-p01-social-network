<?php

namespace Fagathe\GatherCore\Repository;

use Fagathe\GatherCore\Entity\UserRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\CorePhp\Trait\DatetimeTrait;

/**
 * @extends ServiceEntityRepository<UserRequest>
 */
class UserRequestRepository extends ServiceEntityRepository
{
    use DatetimeTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserRequest::class);
    }

    public function save(UserRequest $userRequest, bool $flush = true, bool $isCreation = false): bool
    {
        $now = $this->now();

        if ($isCreation && $userRequest->getCreatedAt() === null) {
            $userRequest->setCreatedAt($now);
        }

        if (!$isCreation) {
            $userRequest->setUpdatedAt($now);
        }

        try {
            $this->getEntityManager()->persist($userRequest);

            if ($flush) {
                $this->getEntityManager()->flush();
            }

            return true;
        } catch (ORMException) {
            return false;
        }
    }

    public function remove(UserRequest $userRequest, bool $flush = true): bool
    {
        try {
            $this->getEntityManager()->remove($userRequest);

            if ($flush) {
                $this->getEntityManager()->flush();
            }

            return true;
        } catch (ORMException) {
            return false;
        }
    }
}
