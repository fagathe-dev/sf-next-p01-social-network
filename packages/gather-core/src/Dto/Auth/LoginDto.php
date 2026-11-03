<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final class LoginDto
{
    #[Assert\NotBlank(message: 'L\'identifiant (e-mail ou nom d\'utilisateur) est obligatoire.')]
    public string $username = '';

    #[Assert\NotBlank(message: 'Le mot de passe est obligatoire.')]
    public string $password = '';
}