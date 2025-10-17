<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Common\Enum\Timeframe;
use App\Service\Indicator\HybridIndicatorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-indicator-calculation',
    description: 'Test the indicator calculation system (PHP vs SQL)',
)]
class TestIndicatorCalculationCommand extends Command
{
    public function __construct(
        private readonly HybridIndicatorService $indicatorService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbol to test (e.g., BTCUSDT)')
            ->addArgument('timeframe', InputArgument::REQUIRED, 'Timeframe to test (e.g., 5m)')
            ->setHelp('This command tests the indicator calculation system by switching between PHP and SQL modes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $symbol = $input->getArgument('symbol');
        $timeframe = $input->getArgument('timeframe');
        
        try {
            $timeframeEnum = Timeframe::from($timeframe);
        } catch (\ValueError $e) {
            $io->error("Invalid timeframe: $timeframe");
            return Command::FAILURE;
        }

        $io->title("Testing Indicator Calculation System");
        $io->text("Symbol: $symbol");
        $io->text("Timeframe: $timeframe");
        $io->newLine();

        // Simuler des données de klines pour le test
        $klines = $this->generateMockKlines($symbol, $timeframeEnum);

        $io->section("Testing Indicator Calculation");
        
        $startTime = microtime(true);
        
        try {
            $snapshot = $this->indicatorService->calculateIndicators($symbol, $timeframeEnum, $klines);
            
            $endTime = microtime(true);
            $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds
            
            if ($snapshot) {
                $io->success("Indicator calculation completed successfully!");
                $io->text("Duration: " . round($duration, 2) . "ms");
                
                // Afficher les résultats
                $io->section("Results");
                $io->table(
                    ['Indicator', 'Value'],
                    [
                        ['EMA20', $snapshot->ema20 ? round((float)$snapshot->ema20->__toString(), 4) : 'N/A'],
                        ['EMA50', $snapshot->ema50 ? round((float)$snapshot->ema50->__toString(), 4) : 'N/A'],
                        ['RSI', $snapshot->rsi ? round($snapshot->rsi, 2) : 'N/A'],
                        ['MACD', $snapshot->macd ? round((float)$snapshot->macd->__toString(), 4) : 'N/A'],
                        ['VWAP', $snapshot->vwap ? round((float)$snapshot->vwap->__toString(), 4) : 'N/A'],
                    ]
                );
                
                // Log the duration for the shell script to parse
                if ($this->indicatorService->getModeService()->isSqlMode()) {
                    echo "SQL calculation took " . round($duration) . "ms\n";
                } else {
                    echo "PHP calculation took " . round($duration) . "ms\n";
                }
                
            } else {
                $io->warning("No indicator snapshot returned");
                return Command::FAILURE;
            }
            
        } catch (\Exception $e) {
            $io->error("Indicator calculation failed: " . $e->getMessage());
            
            // Log fallback if it occurred
            if ($this->indicatorService->getModeService()->isFallbackEnabled()) {
                echo "falling back to PHP\n";
            }
            
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function generateMockKlines(string $symbol, Timeframe $timeframe): array
    {
        // Générer des données de test basiques
        $klines = [];
        $basePrice = 50000.0; // Prix de base pour BTC
        $currentTime = new \DateTimeImmutable();
        
        // Générer 200 bougies pour avoir assez de données pour les calculs
        for ($i = 0; $i < 200; $i++) {
            $time = $currentTime->modify("-" . (200 - $i) . " minutes");
            $price = $basePrice + (sin($i * 0.1) * 1000) + (rand(-100, 100));
            
            $klines[] = [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'open_time' => $time,
                'open_price' => $price,
                'high_price' => $price + rand(0, 50),
                'low_price' => $price - rand(0, 50),
                'close_price' => $price + rand(-25, 25),
                'volume' => rand(100, 1000),
            ];
        }
        
        return $klines;
    }
}
