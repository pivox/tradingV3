<?php

declare(strict_types=1);

namespace App\MtfValidator\Command;

use App\MtfValidator\Service\MtfService;
use App\Provider\Bitmart\Http\BitmartHttpClientPublic;
use App\MtfValidator\Repository\MtfSwitchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;

#[AsCommand(
    name: 'app:test-mtf-run',
    description: 'Teste l\'endpoint /api/mtf/run'
)]
class TestMtfRunCommand extends Command
{
    public function __construct(
        private readonly MtfService $mtfService,
        private readonly BitmartHttpClientPublic $bitmartClient,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly LoggerInterface $mtfLogger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbols', 's', InputOption::VALUE_OPTIONAL, 'Symboles à tester (séparés par des virgules)', 'BTCUSDT,ETHUSDT')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Mode dry-run')
            ->addOption('force-run', 'f', InputOption::VALUE_NONE, 'Forcer l\'exécution même si les kill switches sont OFF')
            ->addOption('url', 'u', InputOption::VALUE_OPTIONAL, 'URL de base de l\'API', 'http://localhost:8082')
            ->setHelp('
Cette commande teste l\'endpoint /api/mtf/run.

Exemples:
  php bin/console app:test-mtf-run                           # Test avec BTCUSDT,ETHUSDT
  php bin/console app:test-mtf-run --symbols=BTCUSDT        # Test avec BTCUSDT seulement
  php bin/console app:test-mtf-run --dry-run                # Mode dry-run
  php bin/console app:test-mtf-run --force-run              # Forcer l\'exécution
  php bin/console app:test-mtf-run --url=http://localhost:8082  # URL personnalisée
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $symbols = explode(',', $input->getOption('symbols'));
        $dryRun = $input->getOption('dry-run');
        $forceRun = $input->getOption('force-run');
        $baseUrl = $input->getOption('url');
        $verbose = $input->getOption('verbose');

        $io->title('Test de l\'endpoint /api/mtf/run');
        $io->text('URL: ' . $baseUrl);
        $io->text('Symboles: ' . implode(', ', $symbols));
        $io->text('Mode dry-run: ' . ($dryRun ? 'OUI' : 'NON'));
        $io->text('Force run: ' . ($forceRun ? 'OUI' : 'NON'));

        try {
            // Test de connectivité
            $io->section('Test de connectivité');
            if (!$this->testConnectivity($io, $baseUrl)) {
                return Command::FAILURE;
            }

            // Test de l'endpoint
            $io->section('Test de l\'endpoint /api/mtf/run');
            $result = $this->testMtfRunEndpoint($io, $baseUrl, $symbols, $dryRun, $forceRun, $verbose);

            if ($result) {
                $io->success('Test de l\'endpoint réussi !');
                return Command::SUCCESS;
            } else {
                $io->error('Test de l\'endpoint échoué !');
                return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $io->error('Erreur lors du test: ' . $e->getMessage());
            $this->mtfLogger->error('[Test MTF Run] Test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function testConnectivity(SymfonyStyle $io, string $baseUrl): bool
    {
        try {
            $client = HttpClient::create();
            $response = $client->request('GET', $baseUrl . '/api/mtf/status');

            if ($response->getStatusCode() === 200) {
                $io->text('✅ API accessible');
                return true;
            } else {
                $io->text('❌ API inaccessible (code: ' . $response->getStatusCode() . ')');
                return false;
            }
        } catch (\Exception $e) {
            $io->text('❌ Erreur de connectivité: ' . $e->getMessage());
            return false;
        }
    }

    private function testMtfRunEndpoint(SymfonyStyle $io, string $baseUrl, array $symbols, bool $dryRun, bool $forceRun, bool $verbose): bool
    {
        try {
            $client = HttpClient::create();

            // Préparer les données de la requête
            $requestData = [
                'symbols' => $symbols,
                'dry_run' => $dryRun,
                'force_run' => $forceRun
            ];

            if ($verbose) {
                $io->text('Données de la requête: ' . json_encode($requestData, JSON_PRETTY_PRINT));
            }

            // Envoyer la requête
            $io->text('Envoi de la requête POST /api/mtf/run...');
            $response = $client->request('POST', $baseUrl . '/api/mtf/run', [
                'json' => $requestData,
                'timeout' => 60 // 60 secondes de timeout
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray(false);

            if ($verbose) {
                $io->text('Code de réponse: ' . $statusCode);
                $io->text('Contenu de la réponse: ' . json_encode($content, JSON_PRETTY_PRINT));
            }

            if ($statusCode === 200) {
                $io->text('✅ Requête réussie');

                // Afficher le résumé
                if (isset($content['data']['summary'])) {
                    $summary = $content['data']['summary'];
                    $io->section('Résumé de l\'exécution');
                    $io->table(
                        ['Métrique', 'Valeur'],
                        [
                            ['Run ID', $summary['run_id']],
                            ['Temps d\'exécution (s)', $summary['execution_time_seconds']],
                            ['Symboles demandés', $summary['symbols_requested']],
                            ['Symboles traités', $summary['symbols_processed']],
                            ['Symboles réussis', $summary['symbols_successful']],
                            ['Symboles échoués', $summary['symbols_failed']],
                            ['Symboles ignorés', $summary['symbols_skipped']],
                            ['Taux de succès (%)', $summary['success_rate']],
                            ['Mode dry-run', $summary['dry_run'] ? 'OUI' : 'NON'],
                            ['Force run', $summary['force_run'] ? 'OUI' : 'NON']
                        ]
                    );
                }

                // Afficher les résultats par symbole
                if (isset($content['data']['results'])) {
                    $io->section('Résultats par symbole');
                    $results = $content['data']['results'];

                    foreach ($results as $symbol => $result) {
                        $status = $result['status'] ?? 'unknown';
                        $reason = $result['reason'] ?? 'N/A';

                        $io->text("{$symbol}: {$status}");
                        if ($status !== 'success' && $reason !== 'N/A') {
                            $io->text("  Raison: {$reason}");
                        }

                        if ($verbose && isset($result['steps'])) {
                            $io->text("  Étapes:");
                            foreach ($result['steps'] as $step => $stepResult) {
                                $stepStatus = $stepResult['status'] ?? 'unknown';
                                $io->text("    {$step}: {$stepStatus}");
                            }
                        }
                    }
                }

                return true;
            } else {
                $io->text('❌ Requête échouée (code: ' . $statusCode . ')');
                if (isset($content['message'])) {
                    $io->text('Message: ' . $content['message']);
                }
                return false;
            }

        } catch (\Exception $e) {
            $io->text('❌ Erreur lors de la requête: ' . $e->getMessage());
            return false;
        }
    }
}
