<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final class ForgotPasswordDto
{
    #[Assert\NotBlank(message: 'L\'adresse e-mail est obligatoire.')]
    #[Assert\Email(message: 'Cette adresse e-mail est invalide.')]
    public string $email = '';
}
