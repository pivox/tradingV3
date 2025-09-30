<?php
// src/Command/Bitmart/GetRecentTradesCommand.php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Bitmart\Http\BitmartHttpClientPublic;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:get-recent-trades',
    description: 'Affiche les derniers trades',
)]
final class GetRecentTradesCommand extends Command
{
    public function __construct(
        private readonly BitmartHttpClientPublic $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole ex: BTCUSDT')
            ->addArgument('limit', InputArgument::OPTIONAL, 'Nb trades', 5);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $symbol = (string) $input->getArgument('symbol');
            $limit  = (int) $input->getArgument('limit');

            $trades = $this->client->getRecentTrades($symbol, $limit);

            foreach ($trades as $t) {
                $io->writeln(json_encode($t, JSON_PRETTY_PRINT));
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
