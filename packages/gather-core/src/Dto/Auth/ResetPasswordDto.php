<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final class ResetPasswordDto
{
    #[Assert\NotBlank(message: 'Le jeton de réinitialisation est obligatoire.')]
    public string $token = '';

    #[Assert\NotBlank(message: 'Le nouveau mot de passe est obligatoire.')]
    #[Assert\Regex(
        pattern: '#^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}$#',
        message: 'Le mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre et 8 caractères minimum.'
    )]
    public string $password = '';
}
