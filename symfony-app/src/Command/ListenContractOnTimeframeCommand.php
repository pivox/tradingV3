<?php

namespace App\Command;

use App\Bitmart\Ws\KlineStream;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'bitmart:listen-contract',
    description: 'Écoute un contrat donné sur un timeframe donné.'
)]
class ListenContractOnTimeframeCommand extends Command
{
    public function __construct(private readonly KlineStream $klineStream)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('contract', InputArgument::REQUIRED, 'Nom du contrat à écouter')
            ->addArgument('timeframe', InputArgument::REQUIRED, 'Timeframe à écouter (ex: 1m, 5m, 1h, etc.)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $contract = $input->getArgument('contract');
        $timeframe = $input->getArgument('timeframe');

        $output->writeln("Écoute du contrat <info>$contract</info> sur le timeframe <info>$timeframe</info>...");

        $this->klineStream->listen($contract, $timeframe);

        return Command::SUCCESS;
    }

    // private function getKlineData($contract, $timeframe) { ... }
}
