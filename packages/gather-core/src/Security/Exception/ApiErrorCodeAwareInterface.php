<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Security\Exception;

/**
 * Exception d'authentification exposant un code métier stable au client (ex. "email_not_verified"),
 * renvoyé dans la réponse JSON en plus du message pour que le front adapte son affichage.
 */
interface ApiErrorCodeAwareInterface
{
    public function getErrorCode(): string;
}
