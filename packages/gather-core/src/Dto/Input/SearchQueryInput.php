<?php

declare(strict_types=1);

namespace Fagathe\GatherCore\Dto\Input;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SearchQueryInput
{
    public function __construct(
        #[Assert\Length(max: 120)]
        public string $q = '',

        #[Assert\Choice(choices: ['all', 'users', 'groups', 'posts'])]
        public string $type = 'all',
    ) {}
}
