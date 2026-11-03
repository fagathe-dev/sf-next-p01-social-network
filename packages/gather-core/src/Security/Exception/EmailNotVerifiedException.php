<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Security\Exception;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Compte dont l'adresse e-mail n'a pas encore été vérifiée (lien reçu à l'inscription).
 */
final class EmailNotVerifiedException extends CustomUserMessageAccountStatusException implements ApiErrorCodeAwareInterface
{
    public const ERROR_CODE = 'email_not_verified';
    public const MESSAGE = 'Veuillez vérifier votre adresse e-mail avant de vous connecter.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }
}
