<?php

namespace Fagathe\GatherCore\Dto\Account;

use Symfony\Component\Validator\Constraints as Assert;

final class ChangePasswordDto
{
    #[Assert\NotBlank(message: 'Le mot de passe actuel est obligatoire.')]
    public string $currentPassword = '';

    #[Assert\NotBlank(message: 'Le nouveau mot de passe est obligatoire.')]
    #[Assert\Regex(
        pattern: '#^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}$#',
        message: 'Le mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre et 8 caractères minimum.'
    )]
    public string $newPassword = '';
}
