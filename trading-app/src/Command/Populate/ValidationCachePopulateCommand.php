<?php

declare(strict_types=1);

namespace App\Command\Populate;

use App\Domain\Common\Dto\ValidationStateDto;
use App\Domain\Common\Enum\Timeframe;
use App\Infrastructure\Cache\DbValidationCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'populate:validation-cache',
    description: 'Peuple la table validation_cache avec des données de test'
)]
class ValidationCachePopulateCommand extends Command
{
    public function __construct(
        private readonly DbValidationCache $validationCache
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole à peupler (ex: BTCUSDT)')
            ->addArgument('timeframe', InputArgument::REQUIRED, 'Timeframe (ex: 1h, 4h, 15m)')
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Nombre d\'entrées à créer', 10)
            ->addOption('start-date', 's', InputOption::VALUE_OPTIONAL, 'Date de début (Y-m-d H:i:s)', '2024-01-01 00:00:00')
            ->addOption('expiration-minutes', 'e', InputOption::VALUE_OPTIONAL, 'Minutes avant expiration', 5)
            ->addOption('verbose', 'v', InputOption::VALUE_NONE, 'Affichage détaillé');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $symbol = strtoupper($input->getArgument('symbol'));
        $timeframe = Timeframe::from($input->getArgument('timeframe'));
        $count = (int) $input->getOption('count');
        $startDate = new \DateTimeImmutable($input->getOption('start-date'), new \DateTimeZone('UTC'));
        $expirationMinutes = (int) $input->getOption('expiration-minutes');
        $verbose = $input->getOption('verbose');

        $io->title("Peuplement du cache de validation");
        $io->info([
            "Symbole: $symbol",
            "Timeframe: {$timeframe->value}",
            "Nombre: $count",
            "Date de début: " . $startDate->format('Y-m-d H:i:s'),
            "Expiration: {$expirationMinutes} minutes"
        ]);

        $successCount = 0;
        $errorCount = 0;

        for ($i = 0; $i < $count; $i++) {
            try {
                $klineTime = $this->calculateKlineTime($startDate, $timeframe, $i);
                $status = $this->generateTestStatus($klineTime);
                $details = $this->generateTestDetails($status, $klineTime);
                
                $this->validationCache->cacheMtfValidation(
                    $symbol,
                    $timeframe,
                    $klineTime,
                    $status,
                    $details,
                    $expirationMinutes
                );
                
                $successCount++;

                if ($verbose) {
                    $io->writeln("✓ Cache créé: {$status} pour {$klineTime->format('Y-m-d H:i:s')}");
                }
            } catch (\Exception $e) {
                $errorCount++;
                $io->error("Erreur pour l'itération $i: " . $e->getMessage());
            }
        }

        $io->success([
            "Peuplement terminé !",
            "✓ Entrées créées: $successCount",
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

    private function generateTestStatus(\DateTimeImmutable $klineTime): string
    {
        $timeFactor = $klineTime->getTimestamp() / 1800; // 30 minutes
        $statusValue = sin($timeFactor);
        
        return match (true) {
            $statusValue > 0.5 => 'VALID',
            $statusValue < -0.5 => 'INVALID',
            default => 'PENDING'
        };
    }

    private function generateTestDetails(string $status, \DateTimeImmutable $klineTime): array
    {
        $baseDetails = [
            'test_data' => true,
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'kline_timestamp' => $klineTime->getTimestamp()
        ];

        return match ($status) {
            'VALID' => array_merge($baseDetails, [
                'conditions_passed' => ['ema20_gt_50', 'macd_hist_gt_0', 'rsi_lt_70'],
                'conditions_failed' => [],
                'signal_side' => 'LONG',
                'confidence' => 0.85
            ]),
            'INVALID' => array_merge($baseDetails, [
                'conditions_passed' => [],
                'conditions_failed' => ['ema20_lt_50', 'macd_hist_lt_0', 'rsi_gt_70'],
                'signal_side' => 'NONE',
                'confidence' => 0.15
            ]),
            'PENDING' => array_merge($baseDetails, [
                'conditions_passed' => ['ema20_gt_50'],
                'conditions_failed' => ['macd_hist_lt_0'],
                'signal_side' => 'NONE',
                'confidence' => 0.5,
                'waiting_for' => 'macd_confirmation'
            ])
        };
    }
}
