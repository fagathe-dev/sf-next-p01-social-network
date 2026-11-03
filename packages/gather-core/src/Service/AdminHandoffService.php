<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Service;

use Fagathe\GatherCore\Entity\User;
use Fagathe\GatherCore\Enum\Auth\UserRequestTypeEnum;
use Fagathe\GatherCore\Enum\Auth\UserRole;
use Fagathe\GatherCore\Enum\Auth\UserStatus;

/**
 * Passage sans ressaisie du front vers le back-office. L'API émet un jeton pour un membre de l'équipe
 * déjà connecté ; le back-office l'échange une seule fois contre sa propre session.
 *
 * Jeton de 64 caractères aléatoires, valable 60 secondes, à usage unique ; en émettre un nouveau révoque le précédent.
 */
final class AdminHandoffService
{
    public const TTL_SECONDS = 60;

    public function __construct(
        private readonly UserRequestService $requests,
    ) {}

    public function issue(User $user): ?string
    {
        if (!self::isStaff($user)) {
            return null;
        }

        return $this->requests->createRequest(
            user: $user,
            type: UserRequestTypeEnum::AdminHandoff,
            isOtp: false,
            ttlSeconds: self::TTL_SECONDS,
        )?->getToken();
    }

    /** Le jeton est consommé même si le compte n'a plus accès : il ne peut servir qu'une fois. */
    public function redeem(string $token): ?User
    {
        $request = $token !== '' ? $this->requests->findValidRequest($token, UserRequestTypeEnum::AdminHandoff) : null;
        if ($request === null) {
            return null;
        }

        $this->requests->consume($request);
        $user = $request->getUser();

        return self::isStaff($user) && $user->isVisible() && $user->getEffectiveStatus() === UserStatus::Active ? $user : null;
    }

    private static function isStaff(User $user): bool
    {
        return in_array($user->getRole(), [UserRole::ROLE_MODERATOR, UserRole::ROLE_ADMIN], true);
    }
}
