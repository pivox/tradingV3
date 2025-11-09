<?php

declare(strict_types=1);

namespace App\Indicator\Command;

use App\Indicator\Registry\ConditionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:indicator:conditions:list',
    description: 'Liste toutes les conditions disponibles implémentant ConditionInterface'
)]
final class ListConditionsCommand extends Command
{
    public function __construct(
        private readonly ConditionRegistry $registry
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $names = $this->registry->names();

        if (empty($names)) {
            $io->warning('Aucune condition trouvée.');
            return Command::SUCCESS;
        }

        $io->title('Conditions disponibles');
        $io->listing($names);
        $io->info(sprintf('Total: %d condition(s)', count($names)));

        return Command::SUCCESS;
    }
}

