<?php

declare(strict_types=1);

namespace App\Command;

use Fagathe\GatherCore\Service\PopularityCalculatorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

#[AsCommand(name: 'gather:popularity:recalculate', description: 'Recalcule les scores de popularité des posts et de tendance des tags')]
#[AsCronTask('*/15 * * * *')]
final class RecalculatePopularityCommand extends Command
{
    public function __construct(
        private readonly PopularityCalculatorService $calculator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('Posts : %d, tags : %d', $this->calculator->recalculatePosts(), $this->calculator->recalculateTags()));

        return Command::SUCCESS;
    }
}
