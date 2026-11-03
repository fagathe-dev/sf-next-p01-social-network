<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Jeton du lien de vérification reçu à l'inscription.
 */
final class VerifyEmailDto
{
    #[Assert\NotBlank(message: 'Le jeton de validation est obligatoire.')]
    public string $token = '';
}
