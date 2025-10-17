<?php

declare(strict_types=1);

namespace App\Command\Populate;

use App\Domain\Common\Enum\Timeframe;
use App\Infrastructure\Persistence\IndicatorProvider;
use App\Infrastructure\Persistence\SignalPersistenceService;
use App\Infrastructure\Cache\DbValidationCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'populate:test-all',
    description: 'Teste le peuplement de toutes les entités (IndicatorSnapshot, Signal, ValidationCache)'
)]
class TestAllEntitiesCommand extends Command
{
    public function __construct(
        private readonly IndicatorProvider $indicatorProvider,
        private readonly SignalPersistenceService $signalPersistenceService,
        private readonly DbValidationCache $validationCache
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole à tester (ex: BTCUSDT)')
            ->addOption('timeframes', 't', InputOption::VALUE_OPTIONAL, 'Timeframes à tester (séparés par des virgules)', '1h,4h,15m')
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Nombre d\'entrées par entité', 5)
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'Affichage détaillé');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $symbol = strtoupper($input->getArgument('symbol'));
        $timeframes = array_map('trim', explode(',', $input->getOption('timeframes')));
        $count = (int) $input->getOption('count');
        $verbose = $input->getOption('verbose');

        $io->title("Test de peuplement de toutes les entités");
        $io->info([
            "Symbole: $symbol",
            "Timeframes: " . implode(', ', $timeframes),
            "Nombre par entité: $count"
        ]);

        $totalSuccess = 0;
        $totalErrors = 0;

        foreach ($timeframes as $tf) {
            try {
                $timeframe = Timeframe::from($tf);
                $io->section("Test du timeframe: {$timeframe->value}");

                // Test IndicatorSnapshot
                $io->writeln("📊 Test des IndicatorSnapshots...");
                $indicatorResults = $this->testIndicatorSnapshots($symbol, $timeframe, $count, $verbose);
                $totalSuccess += $indicatorResults['success'];
                $totalErrors += $indicatorResults['errors'];

                // Test Signal
                $io->writeln("📈 Test des Signals...");
                $signalResults = $this->testSignals($symbol, $timeframe, $count, $verbose);
                $totalSuccess += $signalResults['success'];
                $totalErrors += $signalResults['errors'];

                // Test ValidationCache
                $io->writeln("💾 Test du ValidationCache...");
                $cacheResults = $this->testValidationCache($symbol, $timeframe, $count, $verbose);
                $totalSuccess += $cacheResults['success'];
                $totalErrors += $cacheResults['errors'];

                $io->success("Timeframe {$timeframe->value} terminé");
            } catch (\Exception $e) {
                $io->error("Erreur pour le timeframe $tf: " . $e->getMessage());
                $totalErrors++;
            }
        }

        $io->success([
            "🎉 Test global terminé !",
            "✓ Succès totaux: $totalSuccess",
            "✗ Erreurs totales: $totalErrors"
        ]);

        return $totalErrors === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function testIndicatorSnapshots(string $symbol, Timeframe $timeframe, int $count, bool $verbose): array
    {
        $success = 0;
        $errors = 0;

        for ($i = 0; $i < $count; $i++) {
            try {
                $klineTime = new \DateTimeImmutable("2024-01-01 00:00:00", new \DateTimeZone('UTC'));
                $klineTime = $klineTime->modify("+" . ($i * 60) . " minutes");

                $snapshot = new \App\Domain\Common\Dto\IndicatorSnapshotDto(
                    symbol: $symbol,
                    timeframe: $timeframe,
                    klineTime: $klineTime,
                    ema20: \Brick\Math\BigDecimal::of(50000 + $i * 100),
                    ema50: \Brick\Math\BigDecimal::of(49000 + $i * 100),
                    rsi: 50 + $i,
                    meta: ['test' => true, 'iteration' => $i]
                );

                $this->indicatorProvider->saveIndicatorSnapshot($snapshot);
                $success++;

                if ($verbose) {
                    echo "  ✓ IndicatorSnapshot $i créé\n";
                }
            } catch (\Exception $e) {
                $errors++;
                if ($verbose) {
                    echo "  ✗ Erreur IndicatorSnapshot $i: " . $e->getMessage() . "\n";
                }
            }
        }

        return ['success' => $success, 'errors' => $errors];
    }

    private function testSignals(string $symbol, Timeframe $timeframe, int $count, bool $verbose): array
    {
        $success = 0;
        $errors = 0;
        $signals = [];

        for ($i = 0; $i < $count; $i++) {
            try {
                $klineTime = new \DateTimeImmutable("2024-01-01 00:00:00", new \DateTimeZone('UTC'));
                $klineTime = $klineTime->modify("+" . ($i * 60) . " minutes");

                $side = $i % 2 === 0 ? \App\Domain\Common\Enum\SignalSide::LONG : \App\Domain\Common\Enum\SignalSide::SHORT;
                
                $signal = new \App\Domain\Common\Dto\SignalDto(
                    symbol: $symbol,
                    timeframe: $timeframe,
                    klineTime: $klineTime,
                    side: $side,
                    score: 0.5 + ($i * 0.1),
                    trigger: 'test_trigger',
                    meta: ['test' => true, 'iteration' => $i]
                );

                $signals[] = $signal;
                $success++;

                if ($verbose) {
                    echo "  ✓ Signal $i créé ({$side->value})\n";
                }
            } catch (\Exception $e) {
                $errors++;
                if ($verbose) {
                    echo "  ✗ Erreur Signal $i: " . $e->getMessage() . "\n";
                }
            }
        }

        // Persister en batch
        if (!empty($signals)) {
            try {
                $this->signalPersistenceService->persistSignals($signals);
                if ($verbose) {
                    echo "  ✓ Batch de " . count($signals) . " signaux persisté\n";
                }
            } catch (\Exception $e) {
                $errors += count($signals);
                $success = 0;
                if ($verbose) {
                    echo "  ✗ Erreur batch signals: " . $e->getMessage() . "\n";
                }
            }
        }

        return ['success' => $success, 'errors' => $errors];
    }

    private function testValidationCache(string $symbol, Timeframe $timeframe, int $count, bool $verbose): array
    {
        $success = 0;
        $errors = 0;

        for ($i = 0; $i < $count; $i++) {
            try {
                $klineTime = new \DateTimeImmutable("2024-01-01 00:00:00", new \DateTimeZone('UTC'));
                $klineTime = $klineTime->modify("+" . ($i * 60) . " minutes");

                $status = $i % 3 === 0 ? 'VALID' : ($i % 3 === 1 ? 'INVALID' : 'PENDING');
                
                $this->validationCache->cacheMtfValidation(
                    $symbol,
                    $timeframe,
                    $klineTime,
                    $status,
                    ['test' => true, 'iteration' => $i, 'status' => $status],
                    5
                );

                $success++;

                if ($verbose) {
                    echo "  ✓ ValidationCache $i créé ($status)\n";
                }
            } catch (\Exception $e) {
                $errors++;
                if ($verbose) {
                    echo "  ✗ Erreur ValidationCache $i: " . $e->getMessage() . "\n";
                }
            }
        }

        return ['success' => $success, 'errors' => $errors];
    }
}
