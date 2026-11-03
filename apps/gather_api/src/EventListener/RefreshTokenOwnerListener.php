<?php

declare(strict_types=1);

namespace App\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Fagathe\GatherCore\Entity\User;

/**
 * Un refresh token est rattaché au compte par son e-mail (getUserIdentifier), sans clé étrangère :
 * il doit suivre un changement d'adresse, sinon la session est perdue, et disparaître avec le compte.
 */
#[AsEntityListener(event: Events::preUpdate, method: 'onUpdate', entity: User::class)]
#[AsEntityListener(event: Events::preRemove, method: 'onRemove', entity: User::class)]
final class RefreshTokenOwnerListener
{
    public function __construct(private readonly Connection $connection) {}

    public function onUpdate(User $user, PreUpdateEventArgs $event): void
    {
        if ($event->hasChangedField('email')) {
            $this->connection->update('refresh_tokens', ['username' => $event->getNewValue('email')], ['username' => $event->getOldValue('email')]);
        }
    }

    public function onRemove(User $user): void
    {
        $this->connection->delete('refresh_tokens', ['username' => $user->getUserIdentifier()]);
    }
}
