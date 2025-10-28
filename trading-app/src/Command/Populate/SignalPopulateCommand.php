<?php

declare(strict_types=1);

namespace App\Command\Populate;

use App\Common\Dto\SignalDto;
use App\Common\Enum\SignalSide;
use App\Common\Enum\Timeframe;
use App\Signal\SignalPersistenceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'populate:signals',
    description: 'Peuple la table signals avec des données de test'
)]
class SignalPopulateCommand extends Command
{
    public function __construct(
        private readonly SignalPersistenceService $signalPersistenceService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole à peupler (ex: BTCUSDT)')
            ->addArgument('timeframe', InputArgument::REQUIRED, 'Timeframe (ex: 1h, 4h, 15m)')
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Nombre de signaux à créer', 10)
            ->addOption('start-date', 's', InputOption::VALUE_OPTIONAL, 'Date de début (Y-m-d H:i:s)', '2024-01-01 00:00:00')
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'Affichage détaillé');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $symbol = strtoupper($input->getArgument('symbol'));
        $timeframe = Timeframe::from($input->getArgument('timeframe'));
        $count = (int) $input->getOption('count');
        $startDate = new \DateTimeImmutable($input->getOption('start-date'), new \DateTimeZone('UTC'));
        $verbose = $input->getOption('verbose');

        $io->title("Peuplement des signaux de trading");
        $io->info([
            "Symbole: $symbol",
            "Timeframe: {$timeframe->value}",
            "Nombre: $count",
            "Date de début: " . $startDate->format('Y-m-d H:i:s')
        ]);

        $successCount = 0;
        $errorCount = 0;
        $signals = [];

        for ($i = 0; $i < $count; $i++) {
            try {
                $klineTime = $this->calculateKlineTime($startDate, $timeframe, $i);
                $signal = $this->createTestSignal($symbol, $timeframe, $klineTime);
                $signals[] = $signal;
                $successCount++;

                if ($verbose) {
                    $io->writeln("✓ Signal créé: {$signal->side->value} pour {$klineTime->format('Y-m-d H:i:s')} (score: {$signal->score})");
                }
            } catch (\Exception $e) {
                $errorCount++;
                $io->error("Erreur pour l'itération $i: " . $e->getMessage());
            }
        }

        // Persister tous les signaux en batch
        if (!empty($signals)) {
            try {
                $this->signalPersistenceService->persistSignals($signals);
                $io->success("Tous les signaux ont été persistés en batch");
            } catch (\Exception $e) {
                $io->error("Erreur lors de la persistance batch: " . $e->getMessage());
                $errorCount += count($signals);
                $successCount = 0;
            }
        }

        $io->success([
            "Peuplement terminé !",
            "✓ Signaux créés: $successCount",
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

    private function createTestSignal(string $symbol, Timeframe $timeframe, \DateTimeImmutable $klineTime): SignalDto
    {
        // Générer des signaux réalistes basés sur le temps
        $timeFactor = $klineTime->getTimestamp() / 3600;
        $side = match (true) {
            sin($timeFactor) > 0.3 => SignalSide::LONG,
            sin($timeFactor) < -0.3 => SignalSide::SHORT,
            default => SignalSide::NONE
        };

        $score = match ($side) {
            SignalSide::LONG, SignalSide::SHORT => 0.5 + (abs(sin($timeFactor)) * 0.4),
            default => null
        };

        $trigger = match ($side) {
            SignalSide::LONG => 'ema20_gt_50',
            SignalSide::SHORT => 'ema20_lt_50',
            default => null
        };

        return new SignalDto(
            symbol: $symbol,
            timeframe: $timeframe,
            klineTime: $klineTime,
            side: $side,
            score: $score,
            trigger: $trigger,
            meta: [
                'test_data' => true,
                'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'time_factor' => $timeFactor,
                'mtf_context' => [
                    '4h' => $side->value,
                    '1h' => $side->value,
                    '15m' => $side->value
                ]
            ]
        );
    }
}
