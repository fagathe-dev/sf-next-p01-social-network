<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fagathe\GatherCore\Entity\LinkPreview;

/**
 * @extends ServiceEntityRepository<LinkPreview>
 */
class LinkPreviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LinkPreview::class);
    }

    public function findByUrl(string $url): ?LinkPreview
    {
        return $this->findOneBy(['urlHash' => LinkPreview::hash($url)]);
    }
}
