<?php
// src/Command/Bitmart/GetFuturesKlinesWindowCommand.php

declare(strict_types=1);

namespace App\Command\Bitmart;

use App\Bitmart\Http\BitmartHttpClientPublic;
use App\Dto\FuturesKlineDto;
use App\Util\TimeframeHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:get-futures-klines-window',
    description: 'Récupère 10 klines puis 2 fenêtres (1: dernières 4, 2: de la 5e à la 10e) sur BitMart Futures V2',
)]
final class GetFuturesKlinesWindowCommand extends Command
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
            ->addArgument('timeframe', InputArgument::REQUIRED, 'Ex: 1m, 3m, 5m, 15m, 1h, 4h, 1d');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $tz   = new \DateTimeZone('Europe/Paris');

        try {
            $symbol    = (string) $input->getArgument('symbol');
            $tfInput   = (string) $input->getArgument('timeframe');

            // 1) Normalise le TF -> minutes (Futures V2 => step en minutes)
            $stepMin   = TimeframeHelper::parseTimeframeToMinutes($tfInput);
            $stepSec   = $stepMin * 60;

            // 2) Récupère l'heure serveur BitMart et construit un anchor UTC
            $nowSec    = (int) \floor($this->client->getSystemTimeMs() / 1000);

            // 3) Fenêtre fermée globale de 10 klines (alignée et close)
            //    toTs_all = début de la dernière bougie CLOSE
            $currentOpen = intdiv($nowSec, $stepSec) * $stepSec; // début tranche courante
            $toTs_all    = $currentOpen - $stepSec;              // dernière clôture
            $fromTs_all  = $toTs_all - 10 * $stepSec;            // 10 bougies

            // ========== APPEL 1 : 10 dernières bougies ==========
            $io->title("1) $symbol – 10 dernières bougies ($tfInput)");
            $klinesAll = $this->client->getFuturesKlines($symbol, $stepMin, $fromTs_all, $toTs_all);

            foreach ($klinesAll as $k) {
                $this->printKline($k, $tz, $io);
            }
            $io->success(sprintf('Total: %d bougies', $klinesAll->count()));

            // ========== APPEL 2 : dernières 4 bougies (du maintenant à la 4e) ==========
            $io->title("2) $symbol – Fenêtre A: dernières 4 bougies ($tfInput)");
            $toTs_A   = $toTs_all;               // dernière close
            $fromTs_A = $toTs_all - 4 * $stepSec;
            $klinesA  = $this->client->getFuturesKlines($symbol, $stepMin, $fromTs_A, $toTs_A);
            foreach ($klinesA as $k) {
                $this->printKline($k, $tz, $io);
            }
            $io->success(sprintf('Fenêtre A: %d bougies', $klinesA->count()));

            // ========== APPEL 3 : de la 5e à la 10e (les 6 précédentes) ==========
            $io->title("3) $symbol – Fenêtre B: de la 5e à la 10e ($tfInput)");
            $toTs_B   = $fromTs_A;               // juste avant A
            $fromTs_B = $fromTs_all;             // début global
            $klinesB  = $this->client->getFuturesKlines($symbol, $stepMin, $fromTs_B, $toTs_B);
            foreach ($klinesB as $k) {
                $this->printKline($k, $tz, $io);
            }
            $io->success(sprintf('Fenêtre B: %d bougies', $klinesB->count()));

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function printKline(FuturesKlineDto $k, \DateTimeZone $tz, SymfonyStyle $io): void
    {
        $dt = (new \DateTimeImmutable('@'.$k->timestamp))->setTimezone($tz);
        $io->writeln(sprintf(
            '[%s] O:%0.2f H:%0.2f L:%0.2f C:%0.2f V:%0.0f',
            $dt->format('Y-m-d H:i:s'),
            $k->open, $k->high, $k->low, $k->close, $k->volume
        ));
    }
}
