<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Connexion sans mot de passe : identifiant + code reçu par e-mail.
 */
final class LoginCodeDto
{
    #[Assert\NotBlank(message: 'L\'identifiant (e-mail ou nom d\'utilisateur) est obligatoire.')]
    public string $username = '';

    #[Assert\NotBlank(message: 'Le code de connexion est obligatoire.')]
    #[Assert\Regex(pattern: '/^\d{6}$/', message: 'Le code de connexion doit contenir exactement 6 chiffres.')]
    public string $code = '';
}
