<?php

declare(strict_types=1);

namespace App\Command\Provider;

use App\Common\Enum\Timeframe;
use App\Repository\KlineRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitmart:check-klines',
    description: 'Vérifie les klines stockées en base de données'
)]
final class CheckKlinesCommand extends Command
{
    public function __construct(
        private readonly KlineRepository $klineRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::OPTIONAL, 'Symbole à vérifier (optionnel)')
            ->addOption('timeframe', 't', InputOption::VALUE_OPTIONAL, 'Timeframe à vérifier (4h|1h|15m|5m|1m)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Nombre de klines à afficher', '10')
            ->addOption('stats', 's', InputOption::VALUE_NONE, 'Afficher les statistiques globales')
            ->addOption('gaps', 'g', InputOption::VALUE_NONE, 'Vérifier les gaps dans les données')
            ->setHelp('
Cette commande permet de vérifier les klines stockées en base de données.

Exemples:
  php bin/console bitmart:check-klines
  php bin/console bitmart:check-klines BTCUSDT
  php bin/console bitmart:check-klines BTCUSDT --timeframe=4h --limit=20
  php bin/console bitmart:check-klines --stats
  php bin/console bitmart:check-klines BTCUSDT --gaps
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $symbol = $input->getArgument('symbol');
        $timeframeStr = $input->getOption('timeframe');
        $limit = (int) $input->getOption('limit');
        $showStats = $input->getOption('stats');
        $checkGaps = $input->getOption('gaps');

        try {
            $io->title('Vérification des klines en base de données');

            if ($showStats) {
                $this->displayGlobalStats($io);
                return Command::SUCCESS;
            }

            if (!$symbol) {
                $io->error('Le symbole est requis (sauf avec --stats)');
                return Command::FAILURE;
            }

            // Validation du timeframe
            $timeframe = null;
            if ($timeframeStr) {
                $timeframe = $this->validateTimeframe($timeframeStr);
                if (!$timeframe) {
                    $io->error("Timeframe invalide: {$timeframeStr}. Valeurs acceptées: 4h, 1h, 15m, 5m, 1m");
                    return Command::FAILURE;
                }
            }

            if ($timeframe) {
                $this->displayKlinesForSymbolAndTimeframe($io, $symbol, $timeframe, $limit, $checkGaps);
            } else {
                $this->displayKlinesForSymbol($io, $symbol, $limit, $checkGaps);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors de la vérification: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function validateTimeframe(string $timeframe): ?Timeframe
    {
        return match ($timeframe) {
            '4h' => Timeframe::TF_4H,
            '1h' => Timeframe::TF_1H,
            '15m' => Timeframe::TF_15M,
            '5m' => Timeframe::TF_5M,
            '1m' => Timeframe::TF_1M,
            default => null
        };
    }

    private function displayGlobalStats(SymfonyStyle $io): void
    {
        $io->section('Statistiques globales des klines');

        $stats = $this->klineRepository->getKlinesStats();

        if (empty($stats)) {
            $io->warning('Aucune kline trouvée en base de données');
            return;
        }

        $headers = ['Symbole', 'Timeframe', 'Nombre', 'Plus ancienne', 'Plus récente'];
        $rows = [];

        $totalKlines = 0;
        $tz = new \DateTimeZone('UTC');
        foreach ($stats as $stat) {
            $rows[] = [
                $stat['symbol'],
                $stat['timeframe']->value ?? $stat['timeframe'],
                number_format($stat['count']),
                $stat['earliest'] ? (new \DateTimeImmutable($stat['earliest'], $tz))->format('Y-m-d H:i:s') : 'N/A',
                $stat['latest'] ? (new \DateTimeImmutable($stat['latest'], $tz))->format('Y-m-d H:i:s') : 'N/A'
            ];
            $totalKlines += $stat['count'];
        }

        $io->table($headers, $rows);
        $io->success(sprintf('Total: %s klines pour %d symboles/timeframes', number_format($totalKlines), count($stats)));
    }

    private function displayKlinesForSymbol(SymfonyStyle $io, string $symbol, int $limit, bool $checkGaps): void
    {
        $io->section(sprintf('Klines pour %s', $symbol));

        $timeframes = [Timeframe::TF_4H, Timeframe::TF_1H, Timeframe::TF_15M, Timeframe::TF_5M, Timeframe::TF_1M];

        foreach ($timeframes as $timeframe) {
            $count = $this->klineRepository->countKlines($symbol, $timeframe);

            if ($count === 0) {
                $io->writeln(sprintf('<comment>%s:</comment> Aucune kline', $timeframe->value));
                continue;
            }

            $io->writeln(sprintf('<info>%s:</info> %d klines', $timeframe->value, $count));

            // Afficher les dernières klines
            $klines = $this->klineRepository->getKlines($symbol, $timeframe, $limit);
            if (!empty($klines)) {
                $this->displayKlinesTable($io, $klines, $timeframe);
            }

            // Vérifier les gaps
            if ($checkGaps) {
                $this->checkGapsForTimeframe($io, $symbol, $timeframe);
            }
        }
    }

    private function displayKlinesForSymbolAndTimeframe(SymfonyStyle $io, string $symbol, Timeframe $timeframe, int $limit, bool $checkGaps): void
    {
        $io->section(sprintf('Klines %s pour %s', $timeframe->value, $symbol));

        $count = $this->klineRepository->countKlines($symbol, $timeframe);

        if ($count === 0) {
            $io->warning(sprintf('Aucune kline %s trouvée pour %s', $timeframe->value, $symbol));
            return;
        }

        $io->info(sprintf('%d klines trouvées', $count));

        // Afficher les klines
        $klines = $this->klineRepository->getKlines($symbol, $timeframe, $limit);
        if (!empty($klines)) {
            $this->displayKlinesTable($io, $klines, $timeframe);
        }

        // Vérifier les gaps
        if ($checkGaps) {
            $this->checkGapsForTimeframe($io, $symbol, $timeframe);
        }
    }

    private function displayKlinesTable(SymfonyStyle $io, array $klines, Timeframe $timeframe): void
    {
        $headers = ['Date/Heure', 'Open', 'High', 'Low', 'Close', 'Volume', 'Source'];
        $rows = [];

        foreach ($klines as $kline) {
            $rows[] = [
                $kline->getOpenTime()->format('Y-m-d H:i:s'),
                $kline->getOpenPrice()->toScale(8)->__toString(),
                $kline->getHighPrice()->toScale(8)->__toString(),
                $kline->getLowPrice()->toScale(8)->__toString(),
                $kline->getClosePrice()->toScale(8)->__toString(),
                $kline->getVolume()->toScale(2)->__toString(),
                $kline->getSource()
            ];
        }

        $io->table($headers, $rows);
    }

    private function checkGapsForTimeframe(SymfonyStyle $io, string $symbol, Timeframe $timeframe): void
    {
        $hasGaps = $this->klineRepository->hasGaps($symbol, $timeframe);

        if ($hasGaps) {
            $io->warning(sprintf('Gaps détectés dans les klines %s pour %s', $timeframe->value, $symbol));

            $gaps = $this->klineRepository->getGaps($symbol, $timeframe);
            foreach ($gaps as $gap) {
                $io->writeln(sprintf(
                    '  Gap: %s à %s (%d périodes manquantes)',
                    $gap['start']->format('Y-m-d H:i:s'),
                    $gap['end']->format('Y-m-d H:i:s'),
                    $gap['missing_periods']
                ));
            }
        } else {
            $io->writeln(sprintf('<info>✓</info> Aucun gap détecté dans les klines %s', $timeframe->value));
        }
    }
}
