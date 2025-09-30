<?php
// src/Command/Bitmart/GetFuturesKlinesCommand.php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Bitmart\Http\BitmartHttpClientPublic;
use App\Dto\FuturesKlineDto;
use App\Util\GranularityHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:get-futures-klines',
    description: 'Récupère des bougies futures clôturées',
)]
final class GetFuturesKlinesCommand extends Command
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
            ->addArgument('granularity', InputArgument::REQUIRED, 'Intervalle en secondes (ex: 60)')
            ->addArgument('limit', InputArgument::OPTIONAL, 'Nb bougies', 5);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $symbol      = (string) $input->getArgument('symbol');
            $granularity = (int) $input->getArgument('granularity');
            $granularity     = GranularityHelper::normalizeToMinutes($granularity);
            $limit       = (int) $input->getArgument('limit');
            $end   = (int) floor($this->client->getSystemTimeMs() / 1000);
            $start       = $end - $limit * $granularity;

            $klines = $this->client->getFuturesKlines($symbol, $granularity, limit: 20);

            foreach ($klines as $k) { // $k est un FuturesKlineDto
                $io->writeln(sprintf(
                    '[%s] O:%s H:%s L:%s C:%s V:%s',
                    date('Y-m-d H:i:s', $k->timestamp),
                    $k->open, $k->high, $k->low, $k->close, $k->volume
                ));
            }

            $io->success(sprintf("Total: %d bougies récupérées", $klines->count()));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
