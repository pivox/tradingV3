<?php

namespace App\Command\MTF;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mtf:tick', description: 'Traite tous les contrats activés pour un TF')]
final class MtfTickCommand extends Command
{
    public function __construct(private \App\Service\MTF\MtfSimpleProcessor $processor) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('tf', null, InputOption::VALUE_REQUIRED, '4h|1h|15m|5m|1m');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $tf = strtolower((string)$in->getOption('tf'));
        $this->processor->runForTimeframe($tf, 270);
        return Command::SUCCESS;
    }
}
