<?php

declare(strict_types=1);

namespace App\Command;

use App\Logging\LoggerHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande de test pour valider le système de logging asynchrone
 */
#[AsCommand(
    name: 'app:test-logging',
    description: 'Teste le système de logging asynchrone avec worker Temporal'
)]
final class TestLoggingCommand extends Command
{
    public function __construct(
        private readonly LoggerHelper $loggerHelper,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Nombre de logs à générer', 100)
            ->addOption('batch', 'b', InputOption::VALUE_OPTIONAL, 'Taille du batch', 10)
            ->addOption('delay', 'd', InputOption::VALUE_OPTIONAL, 'Délai entre les batches (ms)', 100)
            ->setHelp('
Cette commande teste le système de logging asynchrone en générant des logs
de test et en mesurant les performances.

Exemples:
  php bin/console app:test-logging                    # Test standard (100 logs)
  php bin/console app:test-logging --count=500       # Test avec 500 logs
  php bin/console app:test-logging --batch=20        # Test avec batch de 20
  php bin/console app:test-logging --delay=50        # Test avec délai de 50ms
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = (int) $input->getOption('count');
        $batchSize = (int) $input->getOption('batch');
        $delay = (int) $input->getOption('delay');

        $io->title('Test du Système de Logging Asynchrone');
        $io->text([
            "Nombre de logs: {$count}",
            "Taille du batch: {$batchSize}",
            "Délai entre batches: {$delay}ms",
        ]);

        $startTime = microtime(true);
        $batches = (int) ceil($count / $batchSize);

        $io->progressStart($batches);

        for ($batch = 0; $batch < $batches; $batch++) {
            $batchStart = microtime(true);
            
            // Générer un batch de logs
            for ($i = 0; $i < $batchSize && ($batch * $batchSize + $i) < $count; $i++) {
                $logIndex = $batch * $batchSize + $i;
                $this->generateTestLog($logIndex);
            }

            $batchTime = microtime(true) - $batchStart;
            
            $io->progressAdvance();
            
            // Délai entre les batches
            if ($delay > 0 && $batch < $batches - 1) {
                usleep($delay * 1000);
            }
        }

        $io->progressFinish();

        $totalTime = microtime(true) - $startTime;
        $logsPerSecond = $count / $totalTime;

        $io->success([
            'Test terminé avec succès !',
            '',
            'Résultats:',
            "• Logs générés: {$count}",
            "• Temps total: " . round($totalTime, 3) . "s",
            "• Débit: " . round($logsPerSecond, 0) . " logs/seconde",
            "• Temps moyen par log: " . round(($totalTime / $count) * 1000, 2) . "ms",
        ]);

        // Attendre un peu pour que les logs soient traités
        $io->text('Attente du traitement des logs par le worker...');
        sleep(2);

        // Vérifier que les fichiers de logs ont été créés
        $this->checkLogFiles($io);

        return Command::SUCCESS;
    }

    private function generateTestLog(int $index): void
    {
        $channels = ['mtf', 'signals', 'positions', 'indicators', 'highconviction'];
        $levels = ['info', 'warning', 'error'];
        $symbols = ['BTCUSDT', 'ETHUSDT', 'ADAUSDT', 'SOLUSDT', 'DOTUSDT'];
        $timeframes = ['1m', '5m', '15m', '1h', '4h'];
        $sides = ['BUY', 'SELL', 'NONE'];

        $channel = $channels[array_rand($channels)];
        $level = $levels[array_rand($levels)];
        $symbol = $symbols[array_rand($symbols)];
        $timeframe = $timeframes[array_rand($timeframes)];
        $side = $sides[array_rand($sides)];

        $message = "Test log #{$index} - {$channel} - {$symbol} - {$timeframe} - {$side}";

        switch ($channel) {
            case 'signals':
                $this->loggerHelper->logSignal($symbol, $timeframe, $side, $message, [
                    'test_index' => $index,
                    'timestamp' => time(),
                ]);
                break;

            case 'positions':
                $this->loggerHelper->logPosition($symbol, 'test', $side, [
                    'test_index' => $index,
                    'timestamp' => time(),
                ]);
                break;

            case 'indicators':
                $this->loggerHelper->logIndicator($symbol, $timeframe, 'EMA20', [
                    'value' => rand(100, 1000),
                    'test_index' => $index,
                ], $message);
                break;

            case 'highconviction':
                $this->loggerHelper->highConviction($message, [
                    'test_index' => $index,
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                ]);
                break;

            default:
                $this->loggerHelper->tradingLog($channel, $message, $symbol, $timeframe, $side, [
                    'test_index' => $index,
                    'timestamp' => time(),
                ]);
                break;
        }
    }

    private function checkLogFiles(SymfonyStyle $io): void
    {
        $logDir = '/var/log/symfony';
        $channels = ['mtf', 'signals', 'positions', 'indicators', 'highconviction'];

        $io->section('Vérification des fichiers de logs');

        foreach ($channels as $channel) {
            $logFile = "{$logDir}/{$channel}.log";
            
            if (file_exists($logFile)) {
                $size = filesize($logFile);
                $io->text("✅ {$channel}.log: " . number_format($size) . " bytes");
            } else {
                $io->text("❌ {$channel}.log: Fichier non trouvé");
            }
        }
    }
}