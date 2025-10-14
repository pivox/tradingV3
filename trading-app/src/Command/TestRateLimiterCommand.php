<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\RateLimiter\TokenBucketRateLimiter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-rate-limiter',
    description: 'Teste le rate limiter'
)]
class TestRateLimiterCommand extends Command
{
    public function __construct(
        private readonly TokenBucketRateLimiter $rateLimiter,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('requests', 'r', InputOption::VALUE_OPTIONAL, 'Nombre de requêtes à tester', 10)
            ->addOption('delay', 'd', InputOption::VALUE_OPTIONAL, 'Délai entre les requêtes (ms)', 100)
            ->setHelp('
Cette commande teste le rate limiter en simulant des requêtes.

Exemples:
  php bin/console app:test-rate-limiter                    # Test avec 10 requêtes
  php bin/console app:test-rate-limiter --requests=20     # Test avec 20 requêtes
  php bin/console app:test-rate-limiter --delay=50        # Délai de 50ms
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $requests = (int) $input->getOption('requests');
        $delay = (int) $input->getOption('delay');

        $io->title('Test du Rate Limiter');
        $io->text("Nombre de requêtes: {$requests}");
        $io->text("Délai entre requêtes: {$delay}ms");

        try {
            // Affichage de la configuration
            $io->section('Configuration du rate limiter');
            $status = $this->rateLimiter->getStatus();
            
            $io->table(
                ['Propriété', 'Valeur'],
                [
                    ['Capacité', $status['capacity']],
                    ['Taux de refill', $status['refill_rate']],
                    ['Intervalle de refill (ms)', $status['refill_interval_ms']],
                    ['Tokens disponibles', $status['tokens_available']],
                    ['Temps jusqu\'au prochain refill (ms)', $status['time_until_next_refill_ms']],
                    ['Utilisation (%)', number_format($status['utilization_percent'], 2)]
                ]
            );

            // Test des requêtes
            $io->section('Test des requêtes');
            $io->progressStart($requests);
            
            $successful = 0;
            $failed = 0;
            $startTime = microtime(true);
            
            for ($i = 0; $i < $requests; $i++) {
                if ($this->rateLimiter->tryConsume(1)) {
                    $successful++;
                } else {
                    $failed++;
                }
                
                $io->progressAdvance();
                
                if ($delay > 0) {
                    usleep($delay * 1000);
                }
            }
            
            $io->progressFinish();
            
            $endTime = microtime(true);
            $totalTime = $endTime - $startTime;
            $avgTime = $totalTime / $requests;
            
            // Résultats
            $io->section('Résultats du test');
            $io->table(
                ['Métrique', 'Valeur'],
                [
                    ['Requêtes réussies', $successful],
                    ['Requêtes échouées', $failed],
                    ['Taux de succès (%)', number_format(($successful / $requests) * 100, 2)],
                    ['Temps total (s)', number_format($totalTime, 3)],
                    ['Temps moyen par requête (ms)', number_format($avgTime * 1000, 2)],
                    ['Requêtes par seconde', number_format($requests / $totalTime, 2)]
                ]
            );

            // Test de la récupération
            $io->section('Test de la récupération');
            $io->text('Attente de la récupération des tokens...');
            
            $recoveryStart = microtime(true);
            $recoveryTime = 0;
            
            while ($this->rateLimiter->getAvailableTokens() < $status['capacity']) {
                usleep(100000); // 100ms
                $recoveryTime = microtime(true) - $recoveryStart;
                
                if ($recoveryTime > 10) { // Timeout après 10 secondes
                    break;
                }
            }
            
            $io->text("Temps de récupération: " . number_format($recoveryTime, 3) . "s");
            
            // Statut final
            $finalStatus = $this->rateLimiter->getStatus();
            $io->table(
                ['Propriété', 'Valeur'],
                [
                    ['Tokens disponibles', $finalStatus['tokens_available']],
                    ['Utilisation (%)', number_format($finalStatus['utilization_percent'], 2)]
                ]
            );

            if ($successful > 0) {
                $io->success('Test du rate limiter réussi');
            } else {
                $io->warning('Aucune requête n\'a réussi');
            }
            
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Erreur lors du test: ' . $e->getMessage());
            $this->logger->error('[Test Rate Limiter] Test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }
}




