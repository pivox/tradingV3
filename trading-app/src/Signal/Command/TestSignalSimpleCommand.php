<?php

declare(strict_types=1);

namespace App\Signal\Command;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Signal\SignalMainProviderInterface;
use App\Contract\Signal\SignalServiceInterface;
use App\Provider\Entity\Contract;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:signal:test-simple',
    description: 'Teste les services de signal sans base de données (version simplifiée)'
)]
final class TestSignalSimpleCommand extends Command
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider,
        private readonly SignalMainProviderInterface $signalMainProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole de trading (ex: BTC_USDT)')
            ->addOption('timeframe', 't', InputOption::VALUE_OPTIONAL, 'Timeframe spécifique (ex: 1h, 4h, 1d) ou "all" pour tous', 'all')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Format de sortie (table, json)', 'table')
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Configuration de test (long, short, both)', 'both')
            ->setHelp('
Cette commande teste les services de signal disponibles pour un symbole donné sans base de données.

Exemples:
  php bin/console app:signal:test-simple BTC_USDT
  php bin/console app:signal:test-simple BTC_USDT --timeframe=1h
  php bin/console app:signal:test-simple BTC_USDT --timeframe=all --format=json
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $symbol = strtoupper($input->getArgument('symbol'));
        $timeframeOption = $input->getOption('timeframe');
        $format = $input->getOption('format');
        $config = $input->getOption('config');

        $io->title("Test des Services de Signal (Simple) - {$symbol}");

        try {
            // Détermination des timeframes à tester
            $timeframesToTest = $this->getTimeframesToTest($timeframeOption, $io);
            if (empty($timeframesToTest)) {
                return Command::FAILURE;
            }

            // Récupération des services de signal disponibles
            $availableServices = $this->signalMainProvider->getTimeframeServices();
            $io->info(sprintf("Services de signal disponibles: %d", count($availableServices)));

            $results = [];

            // Test de chaque timeframe
            foreach ($timeframesToTest as $timeframe) {
                $io->section("Test du timeframe: {$timeframe}");

                $signalService = $this->signalMainProvider->getSignalService($timeframe);
                if (!$signalService) {
                    $io->warning("Aucun service de signal trouvé pour le timeframe: {$timeframe}");
                    continue;
                }

                try {
                    // Récupération des klines via MainProvider
                    $klineProvider = $this->mainProvider->getKlineProvider();
                    $timeframeEnum = Timeframe::from($timeframe);
                    $klines = $klineProvider->getKlines($symbol, $timeframeEnum, 100);

                    if (empty($klines)) {
                        $io->warning("Aucune kline trouvée pour {$symbol} sur {$timeframe}");
                        continue;
                    }

                    $io->info(sprintf("Récupéré %d klines pour %s", count($klines), $timeframe));

                    // Configuration de test
                    $testConfigs = $this->getTestConfigs($config);

                    foreach ($testConfigs as $configName => $testConfig) {
                        $io->info("Test avec configuration: {$configName}");

                        // Créer un contrat factice pour le test
                        $mockContract = $this->createMockContract($symbol);

                        // Évaluation du signal
                        $signalResult = $signalService->evaluate($mockContract, $klines, $testConfig);

                        $result = [
                            'timeframe' => $timeframe,
                            'config' => $configName,
                            'signal' => $signalResult,
                            'klines_count' => count($klines),
                            'timestamp' => time(),
                        ];

                        $results[] = $result;

                        // Affichage du résultat
                        $this->displaySignalResult($io, $result, $format);
                    }

                } catch (\Exception $e) {
                    $io->error("Erreur lors du test du timeframe {$timeframe}: " . $e->getMessage());
                    continue;
                }
            }

            // Résumé final
            $this->displaySummary($io, $results);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error("Erreur générale: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function getTimeframesToTest(string $timeframeOption, SymfonyStyle $io): array
    {
        if ($timeframeOption === 'all') {
            // Récupérer tous les timeframes supportés par les services disponibles
            $availableServices = $this->signalMainProvider->getTimeframeServices();
            $timeframes = [];
            
            foreach ($availableServices as $service) {
                if ($service instanceof SignalServiceInterface) {
                    // Tester les timeframes standards
                    foreach (Timeframe::cases() as $tf) {
                        if ($service->supportsTimeframe($tf->value)) {
                            $timeframes[] = $tf->value;
                        }
                    }
                }
            }
            
            $timeframes = array_unique($timeframes);
            $io->info("Timeframes à tester: " . implode(', ', $timeframes));
            return $timeframes;
        }

        // Timeframe spécifique
        try {
            $timeframe = Timeframe::from($timeframeOption);
            return [$timeframe->value];
        } catch (\ValueError $e) {
            $io->error("Timeframe invalide: {$timeframeOption}. Valeurs acceptées: " . implode(', ', array_column(Timeframe::cases(), 'value')));
            return [];
        }
    }

    private function getTestConfigs(string $config): array
    {
        $configs = [];

        if ($config === 'both' || $config === 'long') {
            $configs['long'] = [
                'side' => 'long',
                'enabled' => true,
                'conditions' => [],
                'requirements' => [],
            ];
        }

        if ($config === 'both' || $config === 'short') {
            $configs['short'] = [
                'side' => 'short',
                'enabled' => true,
                'conditions' => [],
                'requirements' => [],
            ];
        }

        return $configs;
    }

    private function createMockContract(string $symbol): Contract
    {
        // Créer une vraie entité Contract pour le test
        $contract = new Contract();
        $contract->setSymbol($symbol);
        $contract->setName($symbol);
        $contract->setProductType(1);
        $contract->setOpenTimestamp(time());
        $contract->setBaseCurrency('BTC');
        $contract->setQuoteCurrency('USDT');
        $contract->setLastPrice('50000.00');
        $contract->setVolume24h('1000000.00');
        
        return $contract;
    }

    private function displaySignalResult(SymfonyStyle $io, array $result, string $format): void
    {
        $signal = $result['signal'];
        $timeframe = $result['timeframe'];
        $config = $result['config'];

        if ($format === 'json') {
            $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return;
        }

        // Format table
        $io->table(
            ['Propriété', 'Valeur'],
            [
                ['Timeframe', $timeframe],
                ['Configuration', $config],
                ['Signal', $signal['signal'] ?? 'N/A'],
                ['Status', $signal['status'] ?? 'N/A'],
                ['Reason', $signal['reason'] ?? 'N/A'],
                ['Timestamp', $signal['timestamp'] ?? 'N/A'],
                ['Klines utilisées', $result['klines_count']],
            ]
        );

        // Détails des conditions si disponibles
        if (!empty($signal['conditions_long'])) {
            $io->section("Conditions Long");
            $this->displayConditions($io, $signal['conditions_long']);
        }

        if (!empty($signal['conditions_short'])) {
            $io->section("Conditions Short");
            $this->displayConditions($io, $signal['conditions_short']);
        }

        if (!empty($signal['failed_conditions_long'])) {
            $io->section("Conditions Long Échouées");
            $this->displayConditions($io, $signal['failed_conditions_long']);
        }

        if (!empty($signal['failed_conditions_short'])) {
            $io->section("Conditions Short Échouées");
            $this->displayConditions($io, $signal['failed_conditions_short']);
        }
    }

    private function displayConditions(SymfonyStyle $io, array $conditions): void
    {
        if (empty($conditions)) {
            $io->info('Aucune condition');
            return;
        }

        $tableData = [];
        foreach ($conditions as $condition) {
            // Vérifier que $condition est un tableau
            if (!is_array($condition)) {
                continue;
            }
            
            $tableData[] = [
                $condition['name'] ?? 'N/A',
                $condition['value'] ?? 'N/A',
                $condition['threshold'] ?? 'N/A',
                isset($condition['passed']) ? ($condition['passed'] ? '✅' : '❌') : 'N/A',
            ];
        }

        if (!empty($tableData)) {
            $io->table(['Condition', 'Valeur', 'Seuil', 'Statut'], $tableData);
        } else {
            $io->info('Aucune condition valide à afficher');
        }
    }

    private function displaySummary(SymfonyStyle $io, array $results): void
    {
        $io->section('Résumé des Tests');

        $summary = [
            'Timeframes testés' => count(array_unique(array_column($results, 'timeframe'))),
            'Configurations testées' => count($results),
            'Mode' => 'Test simple (sans base de données)',
        ];

        $io->table(['Métrique', 'Valeur'], array_map(fn($k, $v) => [$k, $v], array_keys($summary), $summary));

        $io->success("Test terminé avec succès !");
    }
}
