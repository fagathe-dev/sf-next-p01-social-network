<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Enum\Auth;

enum UserRole: string
{
    case ROLE_USER = 'ROLE_USER';
    case ROLE_MODERATOR = 'ROLE_MODERATOR';
    case ROLE_ADMIN = 'ROLE_ADMIN';

    public static function getRole(null|string|UserRole $role = null): string
    {
        if (is_string($role)) {
            $role = self::tryFrom($role);
        }

        if (!$role instanceof self) {
            return 'Tous les rôles';
        }

        return static::mapRole($role);
    }

    public static function mapRole(UserRole $role): string
    {
        return match ($role) {
            self::ROLE_USER => 'Utilisateur',
            self::ROLE_MODERATOR => 'Modérateur',
            self::ROLE_ADMIN => 'Administrateur',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return [
            'Utilisateur' => self::ROLE_USER->value,
            'Modérateur' => self::ROLE_MODERATOR->value,
            'Administrateur' => self::ROLE_ADMIN->value,
        ];
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(static fn(self $e): string => $e->value, self::cases());
    }
}
