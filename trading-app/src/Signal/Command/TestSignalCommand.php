<?php

declare(strict_types=1);

namespace App\Signal\Command;

use App\Common\Dto\SignalDto;
use App\Common\Enum\SignalSide;
use App\Common\Enum\Timeframe;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Signal\SignalMainProviderInterface;
use App\Contract\Signal\SignalServiceInterface;
use App\Provider\Entity\Contract;
use App\Provider\Repository\ContractRepository;
use App\Signal\SignalPersistenceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:signal:test',
    description: 'Teste les services de signal pour un symbole donné avec données réelles'
)]
final class TestSignalCommand extends Command
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider,
        private readonly SignalMainProviderInterface $signalMainProvider,
        private readonly ContractRepository $contractRepository,
        private readonly ?SignalPersistenceService $signalPersistenceService = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('symbol', InputArgument::REQUIRED, 'Symbole de trading (ex: BTC_USDT)')
            ->addOption('timeframe', 't', InputOption::VALUE_OPTIONAL, 'Timeframe spécifique (ex: 1h, 4h, 1d) ou "all" pour tous', 'all')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Mode dry-run (test sans sauvegarder)')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Format de sortie (table, json)', 'table')
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Configuration de test (long, short, both)', 'both')
            ->setHelp('
Cette commande teste les services de signal disponibles pour un symbole donné.

Exemples:
  php bin/console app:signal:test BTC_USDT
  php bin/console app:signal:test BTC_USDT --timeframe=1h
  php bin/console app:signal:test BTC_USDT --timeframe=all --dry-run
  php bin/console app:signal:test BTC_USDT --format=json --config=long
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $symbol = strtoupper($input->getArgument('symbol'));
        $timeframeOption = $input->getOption('timeframe');
        $dryRun = $input->getOption('dry-run');
        $format = $input->getOption('format');
        $config = $input->getOption('config');

        $io->title("Test des Services de Signal - {$symbol}");

        try {
            // Récupération du contrat
            $contract = $this->contractRepository->findBySymbol($symbol);
            if (!$contract) {
                $io->error("Contrat non trouvé pour le symbole: {$symbol}");
                return Command::FAILURE;
            }

            $io->info("Contrat trouvé: {$contract->getSymbol()}");

            // Détermination des timeframes à tester
            $timeframesToTest = $this->getTimeframesToTest($timeframeOption, $io);
            if (empty($timeframesToTest)) {
                return Command::FAILURE;
            }

            // Récupération des services de signal disponibles
            $availableServices = $this->signalMainProvider->getTimeframeServices();
            $io->info(sprintf("Services de signal disponibles: %d", count($availableServices)));

            $results = [];
            $totalSignals = 0;

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

                        // Évaluation du signal
                        $signalResult = $signalService->evaluate($contract, $klines, $testConfig);

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

                        // Sauvegarde si pas en dry-run
                        if (!$dryRun && !empty($signalResult['signal'])) {
                            $this->persistSignal($io, $signalResult, $contract, $timeframe);
                            $totalSignals++;
                        }
                    }

                } catch (\Exception $e) {
                    $io->error("Erreur lors du test du timeframe {$timeframe}: " . $e->getMessage());
                    continue;
                }
            }

            // Résumé final
            $this->displaySummary($io, $results, $totalSignals, $dryRun);

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

    private function persistSignal(SymfonyStyle $io, array $signalResult, Contract $contract, string $timeframe): void
    {
        if (!$this->signalPersistenceService) {
            $io->note("Service de persistance non disponible - signal non sauvegardé");
            return;
        }

        try {
            // Convertir le résultat en SignalDto pour la persistance
            $signalDto = $this->convertToSignalDto($signalResult, $contract, $timeframe);
            
            if ($signalDto) {
                $this->signalPersistenceService->persistSignal($signalDto);
                $io->info("Signal sauvegardé pour {$contract->getSymbol()} sur {$timeframe}: {$signalResult['signal']}");
            }
            
        } catch (\Exception $e) {
            $io->error("Erreur lors de la sauvegarde du signal: " . $e->getMessage());
        }
    }

    private function convertToSignalDto(array $signalResult, Contract $contract, string $timeframe): ?SignalDto
    {
        // Vérifier qu'on a un signal valide
        if (empty($signalResult['signal']) || $signalResult['signal'] === 'NONE') {
            return null;
        }

        try {
            // Déterminer le côté du signal
            $side = $this->determineSignalSide($signalResult['signal']);
            if ($side === SignalSide::NONE) {
                return null;
            }

            // Créer le SignalDto
            return new SignalDto(
                symbol: $contract->getSymbol(),
                timeframe: Timeframe::from($timeframe),
                klineTime: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                side: $side,
                score: $this->extractScore($signalResult),
                trigger: $signalResult['reason'] ?? null,
                meta: $this->buildMeta($signalResult)
            );

        } catch (\Exception $e) {
            throw new \RuntimeException("Erreur lors de la conversion du signal: " . $e->getMessage(), 0, $e);
        }
    }

    private function determineSignalSide(string $signal): SignalSide
    {
        $signal = strtoupper(trim($signal));
        
        return match ($signal) {
            'LONG', 'BUY', '1' => SignalSide::LONG,
            'SHORT', 'SELL', '-1' => SignalSide::SHORT,
            'NONE', '0', '' => SignalSide::NONE,
            default => SignalSide::NONE,
        };
    }

    private function extractScore(array $signalResult): ?float
    {
        // Essayer d'extraire un score du contexte ou des métadonnées
        if (isset($signalResult['score'])) {
            return (float) $signalResult['score'];
        }

        if (isset($signalResult['context']['score'])) {
            return (float) $signalResult['context']['score'];
        }

        // Calculer un score basé sur les conditions passées
        $conditionsCount = 0;
        $passedCount = 0;

        if (!empty($signalResult['conditions_long'])) {
            $conditionsCount += count($signalResult['conditions_long']);
            $passedCount += count(array_filter($signalResult['conditions_long'], fn($c) => $c['passed'] ?? false));
        }

        if (!empty($signalResult['conditions_short'])) {
            $conditionsCount += count($signalResult['conditions_short']);
            $passedCount += count(array_filter($signalResult['conditions_short'], fn($c) => $c['passed'] ?? false));
        }

        if ($conditionsCount > 0) {
            return round($passedCount / $conditionsCount, 3);
        }

        return null;
    }

    private function buildMeta(array $signalResult): array
    {
        $meta = [];

        // Ajouter les informations de contexte
        if (isset($signalResult['context'])) {
            $meta['context'] = $signalResult['context'];
        }

        if (isset($signalResult['indicator_context'])) {
            $meta['indicators'] = $signalResult['indicator_context'];
        }

        // Ajouter les conditions
        if (!empty($signalResult['conditions_long'])) {
            $meta['conditions_long'] = $signalResult['conditions_long'];
        }

        if (!empty($signalResult['conditions_short'])) {
            $meta['conditions_short'] = $signalResult['conditions_short'];
        }

        if (!empty($signalResult['failed_conditions_long'])) {
            $meta['failed_conditions_long'] = $signalResult['failed_conditions_long'];
        }

        if (!empty($signalResult['failed_conditions_short'])) {
            $meta['failed_conditions_short'] = $signalResult['failed_conditions_short'];
        }

        // Ajouter les requirements
        if (!empty($signalResult['requirements_long'])) {
            $meta['requirements_long'] = $signalResult['requirements_long'];
        }

        if (!empty($signalResult['requirements_short'])) {
            $meta['requirements_short'] = $signalResult['requirements_short'];
        }

        // Ajouter le statut et la raison
        if (isset($signalResult['status'])) {
            $meta['status'] = $signalResult['status'];
        }

        if (isset($signalResult['reason'])) {
            $meta['reason'] = $signalResult['reason'];
        }

        // Ajouter le timestamp
        if (isset($signalResult['timestamp'])) {
            $meta['timestamp'] = $signalResult['timestamp'];
        }

        return $meta;
    }

    private function displaySummary(SymfonyStyle $io, array $results, int $totalSignals, bool $dryRun): void
    {
        $io->section('Résumé des Tests');

        $summary = [
            'Timeframes testés' => count(array_unique(array_column($results, 'timeframe'))),
            'Configurations testées' => count($results),
            'Signaux générés' => $totalSignals,
            'Mode' => $dryRun ? 'Dry-run (non sauvegardé)' : 'Production (sauvegardé)',
        ];

        $io->table(['Métrique', 'Valeur'], array_map(fn($k, $v) => [$k, $v], array_keys($summary), $summary));

        if ($dryRun) {
            $io->note('Mode dry-run activé - aucun signal n\'a été sauvegardé');
        } else {
            $io->success("{$totalSignals} signaux sauvegardés avec succès");
        }
    }
}
