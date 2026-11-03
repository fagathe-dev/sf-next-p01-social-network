<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Identifiant seul (e-mail ou nom d'utilisateur) : demande de code de connexion, renvoi du lien de vérification.
 */
final class IdentifierDto
{
    #[Assert\NotBlank(message: 'L\'identifiant (e-mail ou nom d\'utilisateur) est obligatoire.')]
    public string $username = '';
}
