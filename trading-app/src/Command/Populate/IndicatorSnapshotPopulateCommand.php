<?php

declare(strict_types=1);

namespace App\Command\Populate;

use App\Common\Dto\IndicatorSnapshotDto;
use App\Common\Enum\Timeframe;
use App\Contract\Indicator\IndicatorProviderInterface;
use Brick\Math\BigDecimal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'populate:indicators',
    description: 'Peuple la table indicator_snapshots avec des données de test'
)]
class IndicatorSnapshotPopulateCommand extends Command
{
    public function __construct(
        private readonly IndicatorProviderInterface $indicatorProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole à peupler (ex: BTCUSDT)')
            ->addArgument('timeframe', InputArgument::REQUIRED, 'Timeframe (ex: 1h, 4h, 15m)')
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Nombre de snapshots à créer', 10)
            ->addOption('start-date', 's', InputOption::VALUE_OPTIONAL, 'Date de début (Y-m-d H:i:s)', '2024-01-01 00:00:00');
    }
    //  codex resume 019aceaa-54c7-75b3-b244-0ef0c559eb17

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $symbol = strtoupper($input->getArgument('symbol'));
        $timeframe = Timeframe::from($input->getArgument('timeframe'));
        $count = (int) $input->getOption('count');
        $startDate = new \DateTimeImmutable($input->getOption('start-date'), new \DateTimeZone('UTC'));
        $verbose = $io->isVerbose();

        $io->title("Peuplement des snapshots d'indicateurs");
        $io->info([
            "Symbole: $symbol",
            "Timeframe: {$timeframe->value}",
            "Nombre: $count",
            "Date de début: " . $startDate->format('Y-m-d H:i:s')
        ]);

        $successCount = 0;
        $errorCount = 0;

        for ($i = 0; $i < $count; $i++) {
            try {
                $klineTime = $this->calculateKlineTime($startDate, $timeframe, $i);
                $snapshot = $this->createTestSnapshot($symbol, $timeframe, $klineTime);

                $this->indicatorProvider->saveIndicatorSnapshot($snapshot);
                $successCount++;

                if ($verbose) {
                    $io->writeln("✓ Snapshot créé pour {$klineTime->format('Y-m-d H:i:s')}");
                }
            } catch (\Exception $e) {
                $errorCount++;
                $io->error("Erreur pour l'itération $i: " . $e->getMessage());
            }
        }

        $io->success([
            "Peuplement terminé !",
            "✓ Snapshots créés: $successCount",
            "✗ Erreurs: $errorCount"
        ]);

        return Command::SUCCESS;
    }

    private function calculateKlineTime(\DateTimeImmutable $startDate, Timeframe $timeframe, int $offset): \DateTimeImmutable
    {
        $minutes = match ($timeframe->value) {
            '1m' => 1,
            '5m' => 5,
            '15m' => 15,
            '1h' => 60,
            '4h' => 240,
            default => 60
        };

        return $startDate->modify("+" . ($offset * $minutes) . " minutes");
    }

    private function createTestSnapshot(string $symbol, Timeframe $timeframe, \DateTimeImmutable $klineTime): IndicatorSnapshotDto
    {
        // Générer des valeurs réalistes basées sur le temps
        $basePrice = 50000 + (sin($klineTime->getTimestamp() / 3600) * 5000);
        $volatility = 0.02 + (cos($klineTime->getTimestamp() / 1800) * 0.01);

        return new IndicatorSnapshotDto(
            symbol: $symbol,
            timeframe: $timeframe,
            klineTime: $klineTime,
            ema20: BigDecimal::of($basePrice * (1 + $volatility * 0.5)),
            ema50: BigDecimal::of($basePrice * (1 + $volatility * 0.3)),
            macd: BigDecimal::of($basePrice * $volatility * 0.1),
            macdSignal: BigDecimal::of($basePrice * $volatility * 0.08),
            macdHistogram: BigDecimal::of($basePrice * $volatility * 0.02),
            atr: BigDecimal::of($basePrice * $volatility),
            rsi: 50 + (sin($klineTime->getTimestamp() / 900) * 20),
            vwap: BigDecimal::of($basePrice * (1 + $volatility * 0.1)),
            bbUpper: BigDecimal::of($basePrice * (1 + $volatility * 1.5)),
            bbMiddle: BigDecimal::of($basePrice),
            bbLower: BigDecimal::of($basePrice * (1 - $volatility * 1.5)),
            ma9: BigDecimal::of($basePrice * (1 + $volatility * 0.2)),
            ma21: BigDecimal::of($basePrice * (1 + $volatility * 0.15)),
            meta: [
                'test_data' => true,
                'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'base_price' => $basePrice,
                'volatility' => $volatility
            ]
        );
    }
}
