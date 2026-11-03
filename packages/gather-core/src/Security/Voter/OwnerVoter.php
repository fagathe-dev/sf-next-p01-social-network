<?php

namespace Fagathe\GatherCore\Security\Voter;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

final class OwnerVoter extends Voter
{
    public const IS_OWNER = 'IS_OWNER';
    public const VIEW = 'VIEW';
    public const EDIT = 'EDIT';
    public const DELETE = 'DELETE';

    /**
     * @var array<string> Liste des méthodes à inspecter pour récupérer le propriétaire.
     */
    private const OWNER_GETTER_METHODS = [
        'getOwner',
        'getUser',
        'getAuthor',
        'getCreator',
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        $supportedAttributes = [self::IS_OWNER, self::VIEW, self::EDIT, self::DELETE];

        return in_array($attribute, $supportedAttributes, true)
            && is_object($subject)
            && $this->hasOwnerResource($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof UserInterface) {
            return false;
        }

        $owner = $this->getResourceOwner($subject);

        if (!$owner) {
            return false;
        }

        // Vérification d'égalité stricte par identifiant unique (email ou id)
        return $owner->getUserIdentifier() === $user->getUserIdentifier();
    }

    private function hasOwnerResource(mixed $resource): bool
    {
        foreach (self::OWNER_GETTER_METHODS as $method) {
            if (method_exists($resource, $method)) {
                return true;
            }
        }

        return false;
    }

    private function getResourceOwner(mixed $resource): ?UserInterface
    {
        if (!is_object($resource)) {
            return null;
        }

        foreach (self::OWNER_GETTER_METHODS as $method) {
            if (method_exists($resource, $method)) {
                $owner = $resource->$method();
                if ($owner instanceof UserInterface) {
                    return $owner;
                }
            }
        }

        return null;
    }
}
