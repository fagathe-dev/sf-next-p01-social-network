<?php

namespace Fagathe\GatherCore\Dto\Account;

use Symfony\Component\Validator\Constraints as Assert;

final class UpdateAccountDto
{
    #[Assert\NotBlank(message: 'Le nom d\'utilisateur est obligatoire.')]
    #[Assert\Length(
        min: 3,
        max: 90,
        minMessage: 'Le nom d\'utilisateur doit contenir au moins {{ limit }} caractères.',
        maxMessage: 'Le nom d\'utilisateur ne peut pas dépasser {{ limit }} caractères.'
    )]
    public string $username = '';

    #[Assert\Length(max: 80, maxMessage: 'Le prénom ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $firstname = null;

    #[Assert\Length(max: 80, maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $lastname = null;

    #[Assert\Length(max: 500, maxMessage: 'La biographie ne peut pas dépasser {{ limit }} caractères.')]
    public ?string $bio = null;

    #[Assert\Date(message: 'La date de naissance est invalide.')]
    public ?string $birthDate = null;
}
