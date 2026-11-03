<?php

namespace Fagathe\GatherCore\Dto\Account;

use Symfony\Component\Validator\Constraints as Assert;

final class ChangeEmailDto
{
    #[Assert\NotBlank(message: 'La nouvelle adresse e-mail est obligatoire.')]
    #[Assert\Email(message: 'Cette adresse e-mail est invalide.')]
    #[Assert\Length(max: 180)]
    public string $email = '';
}
