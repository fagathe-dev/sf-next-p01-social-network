<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Account;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Confirmation de la suppression du compte : code reçu par e-mail.
 */
final class DeleteAccountDto
{
    #[Assert\NotBlank(message: 'Le code de confirmation est obligatoire.')]
    #[Assert\Regex(pattern: '/^\d{6}$/', message: 'Le code de confirmation doit contenir exactement 6 chiffres.')]
    public string $code = '';
}
