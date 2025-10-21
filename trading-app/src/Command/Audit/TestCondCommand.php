<?php

namespace App\Command\Audit;

use App\Config\MtfValidationConfig;
use App\Indicator\ConditionLoader\ConditionRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'test:cond',
    description: 'Add a short description for your command',
)]
class TestCondCommand extends Command
{
    public function __construct(
        private ConditionRegistry $conditionRegistry,
        private MtfValidationConfig $mtfValidationConfig
    )
    {
        parent::__construct();
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $config = $this->mtfValidationConfig->getConfig();
        $this->conditionRegistry->load($config);

        $validation = $this->conditionRegistry->getValidation();
        if (!$validation) {
            $io->error('Impossible de charger la configuration mtf_validation.');
            return Command::FAILURE;
        }

        $timeframes = implode(', ', array_keys($validation->getTimeframes()));
        $io->success(sprintf(
            'Configuration MTF chargée (start_from_timeframe=%s, timeframes=%s)',
            $validation->getStartFromTimeframe(),
            $timeframes ?: 'aucun'
        ));

        return Command::SUCCESS;
    }
}
