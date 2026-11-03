<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Security;

use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Auth\UserStatus;
use Fagathe\GatherCore\Security\Exception\EmailNotVerifiedException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Contrôle l'état du compte lors de chaque authentification (login et requêtes JWT).
 *
 * - checkPreAuth  : exécuté AVANT la vérification du mot de passe. N'y placer que des
 *                   contrôles qui ne doivent pas dépendre des identifiants (compte banni, supprimé…).
 * - checkPostAuth : exécuté APRÈS la vérification du mot de passe. Le contrôle "e-mail vérifié"
 *                   est placé ici pour ne pas révéler l'existence d'un compte non vérifié
 *                   à quelqu'un qui ne connaît pas son mot de passe (anti-énumération).
 *
 * Les exceptions levées sont des CustomUserMessageAccountStatusException : ce sont les seules
 * que Symfony ne masque pas derrière un "Bad credentials" (option hide_user_not_found).
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        match ($user->getEffectiveStatus()) {
            UserStatus::Banned => throw new CustomUserMessageAccountStatusException('Ce compte a été banni définitivement.'),
            UserStatus::Suspended => throw new CustomUserMessageAccountStatusException(
                sprintf('Ce compte est suspendu jusqu\'au %s.', $user->getSanctionExpiresAt()?->format('d/m/Y H:i') ?? '(date inconnue)')
            ),
            default => null,
        };
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isVerified()) {
            throw new EmailNotVerifiedException();
        }
    }
}
