<?php

declare(strict_types=1);

namespace App\MtfValidator\Command;

use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\MtfValidatorInterface;
use App\MtfValidator\Service\Helper\OrdersExtractor;
use App\MtfValidator\Entity\MtfSwitch;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\Dto\ContractDto as ProviderContractDto;
use App\Provider\Bitmart\Dto\ContractDto as BitmartContractDto;
use App\Provider\Repository\ContractRepository;
use App\MtfValidator\Repository\MtfSwitchRepository;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Ramsey\Uuid\Uuid;
use SplQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Config\TradeEntryModeContext;
use App\Provider\Context\ExchangeContext;
use App\MtfRunner\Dto\MtfRunnerRequestDto;
use App\MtfRunner\Service\MtfRunnerService;

    #[AsCommand(name: 'mtf:run', description: 'Exécute un cycle MTF pour une liste de symboles (dry-run par défaut) avec un output détaillé')]
class MtfRunCommand extends Command
{
    public function __construct(
        private readonly MtfValidatorInterface $mtfValidator,
        private readonly MtfRunnerService $mtfRunnerService,
        private readonly MainProviderInterface $mainProvider,
        private readonly ContractRepository $contractRepository,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TradeEntryModeContext $modeContext,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('symbols', null, InputOption::VALUE_OPTIONAL, 'Liste de symboles séparés par des virgules (ex: BTCUSDT,ETHUSDT). Par défaut: configurés dans le service')
            ->addOption('dry-run', null, InputOption::VALUE_OPTIONAL, 'Exécuter en mode simulation (1|0)', '1')
            ->addOption('force-run', null, InputOption::VALUE_NONE, 'Force l\'exécution même si les switchs globaux ou symboles sont OFF')
            ->addOption('tf', null, InputOption::VALUE_OPTIONAL, 'Limiter l\'exécution à un unique timeframe (4h|1h|15m|5m|1m)')
            ->addOption('sync-contracts', null, InputOption::VALUE_NONE, 'Forcer la synchronisation (fetch + upsert) des contrats au démarrage (activé par défaut)')
            ->addOption('force-timeframe-check', null, InputOption::VALUE_NONE, 'Force l\'analyse du timeframe même si la dernière kline est récente')
            ->addOption('skip-context', null, InputOption::VALUE_NONE, 'Ignorer l\'alignement de contexte pour les TF d\'exécution (bypass de validation contextuelle)')
            ->addOption('lock-per-symbol', null, InputOption::VALUE_NONE, 'Utiliser des verrous par symbole (recommandé pour exécutions unitaires)')
            ->addOption('user-id', null, InputOption::VALUE_OPTIONAL, 'Identifiant utilisateur propagé au pipeline MTF')
            ->addOption('ip-address', null, InputOption::VALUE_OPTIONAL, 'Adresse IP associée à la requête')
            ->addOption('auto-switch-invalid', null, InputOption::VALUE_NONE, 'Ajoute automatiquement les symboles INVALID à mtf_switch après l\'exécution')
            ->addOption('switch-duration', null, InputOption::VALUE_OPTIONAL, 'Durée de désactivation pour les symboles INVALID (ex: 4h, 1d, 1w)', '1d')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Nombre maximum de symboles à traiter quand --symbols est absent (0 = illimité)', '0')
            ->addOption('workers', null, InputOption::VALUE_OPTIONAL, 'Nombre de workers parallèles (1 = mode séquentiel)', '1')
            ->addOption('exchange', null, InputOption::VALUE_OPTIONAL, 'Identifiant de l\'exchange (ex: bitmart)')
            ->addOption('market-type', null, InputOption::VALUE_OPTIONAL, 'Type de marché (perpetual|spot)')
            ->addOption('trade-profile', null, InputOption::VALUE_OPTIONAL, 'Profil TradeEntry/MTF (ex: scalper, regular)')
            ->addOption('validation-mode', null, InputOption::VALUE_OPTIONAL, 'Mode de validation du contexte (pragmatic|strict|ultra-pragmatig)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $commandStart = microtime(true);

        $symbolsOpt = (string) ($input->getOption('symbols') ?? '');
        $symbols = array_values(array_filter(array_map('trim', $symbolsOpt !== '' ? explode(',', $symbolsOpt) : [])));
        $dryRun = ((string) $input->getOption('dry-run')) !== '0';
        $forceRun = (bool) $input->getOption('force-run');
        $currentTf = $input->getOption('tf');
        $currentTf = is_string($currentTf) && $currentTf !== '' ? $currentTf : null;
        $syncContractsOpt = (bool) $input->getOption('sync-contracts');
        $forceTimeframeCheck = (bool) $input->getOption('force-timeframe-check');
        $skipContext = (bool) $input->getOption('skip-context');
        $autoSwitchInvalid = (bool) $input->getOption('auto-switch-invalid');
        $switchDuration = (string) $input->getOption('switch-duration');
        $workers = max(1, (int) $input->getOption('workers'));
        $limitOpt = (int) $input->getOption('limit');
        $limit = $limitOpt < 0 ? 0 : $limitOpt; // 0 = pas de limite
        $lockPerSymbol = (bool) $input->getOption('lock-per-symbol');
        $userId = $input->getOption('user-id');
        $userId = is_string($userId) && $userId !== '' ? $userId : null;
        $ipAddress = $input->getOption('ip-address');
        $ipAddress = is_string($ipAddress) && $ipAddress !== '' ? $ipAddress : null;

        // Context options (default Bitmart/Perpetual)
        $exchangeOpt = $input->getOption('exchange');
        $marketTypeOpt = $input->getOption('market-type');
        $exchange = Exchange::BITMART;
        if (is_string($exchangeOpt) && $exchangeOpt !== '') {
            $exchange = match (strtolower(trim($exchangeOpt))) {
                'bitmart' => Exchange::BITMART,
                default => Exchange::BITMART,
            };
        }
        $marketType = MarketType::PERPETUAL;
        if (is_string($marketTypeOpt) && $marketTypeOpt !== '') {
            $marketType = match (strtolower(trim($marketTypeOpt))) {
                'perpetual', 'perp', 'future', 'futures' => MarketType::PERPETUAL,
                'spot' => MarketType::SPOT,
                default => MarketType::PERPETUAL,
            };
        }
        $context = new ExchangeContext($exchange, $marketType);
        $profileOpt = $input->getOption('trade-profile');
        $profile = is_string($profileOpt) && $profileOpt !== '' ? trim($profileOpt) : null;
        
        // Injection automatique du profile depuis la configuration si non fourni
        if ($profile === null) {
            $enabledModes = $this->modeContext->getEnabledModes();
            if (!empty($enabledModes)) {
                $profile = $enabledModes[0]['name'] ?? null;
                if ($profile !== null) {
                    $io->note(sprintf('Profile automatique injecté depuis la configuration: %s', $profile));
                }
            }
        }
        
        $validationModeOpt = $input->getOption('validation-mode');
        $validationMode = is_string($validationModeOpt) && $validationModeOpt !== '' ? strtolower(trim($validationModeOpt)) : null;

        $io->title('MTF Run');
        $io->text([
            'Options:',
            sprintf('- symbols: %s', $symbols ? implode(', ', $symbols) : '(par défaut)'),
            sprintf('- dry-run: %s', $dryRun ? 'oui' : 'non'),
            sprintf('- force-run: %s', $forceRun ? 'oui' : 'non'),
            sprintf('- tf: %s', $currentTf ?? '(multi-tf)'),
            sprintf('- force-timeframe-check: %s', $forceTimeframeCheck ? 'oui' : 'non'),
            sprintf('- auto-switch-invalid: %s', $autoSwitchInvalid ? 'oui' : 'non'),
            sprintf('- switch-duration: %s', $autoSwitchInvalid ? $switchDuration : 'N/A'),
            sprintf('- limit: %s', $symbols ? 'N/A (symbols fourni)' : ($limit === 0 ? 'illimité' : (string)$limit)),
            sprintf('- workers: %d', $workers),
        ]);

        $io->note(sprintf('Démarrage de l\'exécution MTF à %s', date('Y-m-d H:i:s')));

        try {
            $shouldSyncContracts = $syncContractsOpt || empty($symbols);

            // Cette section gère la synchronisation des contrats selon l'option fournie
//            if (false) {
//                $io->section('Synchronisation des contrats (provider)');
//                try {
//                    $symbolsBefore = count($symbols);
//                    // Utiliser ignoreLimits=true pour récupérer TOUS les symboles actifs sans limite top_n/mid_n
//                    $symbols = $this->contractRepository->allActiveSymbolNames($symbols, true);
//                    $count = count($symbols);
//                    if ($count > 0) {
//                        $io->success(sprintf(
//                            '%d symbole(s) actif(s) récupéré(s) depuis la base de données (avant filtrage: %d)',
//                            $count,
//                            $symbolsBefore
//                        ));
//                        if ($output->isVerbose()) {
//                            $io->writeln(sprintf('Symboles récupérés: %s', implode(', ', array_slice($symbols, 0, 20)) . ($count > 20 ? '...' : '')));
//                        }
//                    } else {
//                        $io->warning('Aucun symbole actif trouvé dans la base de données');
//                    }
//                } catch (\Throwable $e) {
//                    $io->warning('Récupération des symboles actifs échouée: ' . $e->getMessage());
//                }
//            } else {
//                $io->section('Synchronisation des contrats (provider)');
//                $io->writeln('Ignorée (liste de symboles fournie sans --sync-contracts).');
//            }

            if (empty($symbols)) {
                // Utiliser ignoreLimits=true pour récupérer TOUS les symboles actifs sans limite top_n/mid_n
                $symbols = $this->contractRepository->allActiveSymbolNames([], false, $profile);
                if ($limit > 0) {
                    $symbols = array_slice($symbols, 0, $limit);
                }
            }


            if (empty($symbols)) {
                $io->warning('Aucun symbole actif trouvé');
                return Command::SUCCESS;
            }

            $io->section('Exécution MTF (Runner)');

            // Construire la requête pour le MtfRunnerService
            $runnerRequest = MtfRunnerRequestDto::fromArray([
                'symbols' => $symbols,
                'dry_run' => $dryRun,
                'force_run' => $forceRun,
                'current_tf' => $currentTf,
                'force_timeframe_check' => $forceTimeframeCheck,
                'skip_context' => $skipContext,
                'lock_per_symbol' => $lockPerSymbol,
                'skip_open_state_filter' => true,
                'user_id' => $userId,
                'ip_address' => $ipAddress,
                'exchange' => $exchange->value,
                'market_type' => $marketType->value,
                'workers' => $workers,
                'sync_tables' => true,
                'process_tp_sl' => true,
                'profile' => $profile,
                'validation_mode' => $validationMode,
            ]);

            $runnerResult = $this->mtfRunnerService->run($runnerRequest);
            $runnerSummary = is_array($runnerResult['summary'] ?? null) ? $runnerResult['summary'] : [];
            $details = is_array($runnerResult['results'] ?? null) ? $runnerResult['results'] : [];
            $errors = is_array($runnerResult['errors'] ?? null) ? $runnerResult['errors'] : [];

            // Construire un résumé CLI cohérent avec l'affichage
            $summary = [
                'run_id' => $runnerSummary['run_id'] ?? ($runnerSummary['runId'] ?? 'n/a'),
                'execution_time_seconds' => (float) ($runnerSummary['execution_time_seconds'] ?? 0.0),
                'symbols_requested' => (int) ($runnerSummary['symbols_requested'] ?? count($symbols)),
                'symbols_processed' => (int) ($runnerSummary['symbols_processed'] ?? count($details)),
                'symbols_successful' => (int) ($runnerSummary['symbols_successful'] ?? 0),
                'symbols_failed' => (int) ($runnerSummary['symbols_failed'] ?? 0),
                'symbols_skipped' => (int) ($runnerSummary['symbols_skipped'] ?? 0),
                'success_rate' => (float) ($runnerSummary['success_rate'] ?? 0.0),
                'dry_run' => $dryRun,
                'force_run' => $forceRun,
                'current_tf' => $currentTf,
                'lock_per_symbol' => $lockPerSymbol,
                'user_id' => $userId,
                'ip_address' => $ipAddress,
                'timestamp' => (string) ($runnerSummary['timestamp'] ?? date('Y-m-d H:i:s')),
                'status' => (string) ($runnerSummary['status'] ?? (empty($errors) ? 'completed' : 'completed_with_errors')),
            ];

            $this->renderFinalReport($io, $summary, $details, $commandStart);

            if ($autoSwitchInvalid && !$dryRun) {
                $this->processInvalidSymbols($io, $details, $switchDuration);
            }

            if (!empty($errors)) {
                foreach ($errors as $message) {
                    $formattedError = $this->formatErrorForDisplay($message);
                    if ($formattedError !== null) {
                        $io->warning($formattedError);
                    }
                }
                $io->warning('MTF run terminé avec des erreurs.');
                return Command::FAILURE;
            }

            $io->success(sprintf('MTF run terminé en %s', $this->formatDuration(microtime(true) - $commandStart)));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Erreur après %s: %s', $this->formatDuration(microtime(true) - $commandStart), $e->getMessage()));
            // Afficher la trace si le niveau de verbosité est élevé ou si APP_DEBUG est activé
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE || (getenv('APP_DEBUG') === '1')) {
                $io->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    /**
     * @param string[] $symbols
     * @param array{dry_run: bool, force_run: bool, current_tf: ?string, force_timeframe_check: bool, auto_switch_invalid: bool, switch_duration: string, profile?: ?string, validation_mode?: ?string} $options
     * @return array{summary: array, details: array, errors: array}
     */
    private function runSequential(SymfonyStyle $io, array $symbols, array $options): array
    {
        $mtfRunRequestDto = MtfRunRequestDto::fromArray([
            'symbols' => $symbols,
            'dry_run' => $options['dry_run'],
            'force_run' => $options['force_run'],
            'current_tf' => $options['current_tf'],
            'force_timeframe_check' => $options['force_timeframe_check'],
            'skip_context' => (bool)($options['skip_context'] ?? false),
            'lock_per_symbol' => (bool)($options['lock_per_symbol'] ?? false),
            'skip_open_state_filter' => (bool)($options['skip_open_filter'] ?? false),
            'user_id' => $options['user_id'] ?? null,
            'ip_address' => $options['ip_address'] ?? null,
            'exchange' => $options['exchange'] ?? \App\Common\Enum\Exchange::BITMART->value,
            'market_type' => $options['market_type'] ?? \App\Common\Enum\MarketType::PERPETUAL->value,
            'profile' => $options['profile'] ?? null,
            'validation_mode' => $options['validation_mode'] ?? null,
        ]);
        $response = $this->mtfValidator->run($mtfRunRequestDto);

        // Construire les détails à partir de la réponse
        $details = [];
        foreach ($response->results as $entry) {
            if (!isset($entry['symbol'], $entry['result'])) {
                continue;
            }

            $symbol = (string) $entry['symbol'];
            // Ne pas afficher les lignes de progression pour les entrées FINAL
            if ($symbol === 'FINAL') {
                continue;
            }

            $result = (array) $entry['result'];
            $progress = (array) ($entry['progress'] ?? []);
            if (isset($progress['percentage'], $progress['status'])) {
                $io->writeln(sprintf('[%s%%] %s - %s', (string) $progress['percentage'], $symbol, (string) $progress['status']));
            }
            $details[$symbol] = $result;
        }

        // Construire le résumé attendu par l'affichage
        $summary = [
            'run_id' => $response->runId,
            'execution_time_seconds' => $response->executionTimeSeconds,
            'symbols_requested' => $response->symbolsRequested,
            'symbols_processed' => $response->symbolsProcessed,
            'symbols_successful' => $response->symbolsSuccessful,
            'symbols_failed' => $response->symbolsFailed,
            'symbols_skipped' => $response->symbolsSkipped,
            'success_rate' => $response->successRate,
            'dry_run' => $options['dry_run'],
            'force_run' => $options['force_run'],
            'current_tf' => $options['current_tf'],
            'lock_per_symbol' => $options['lock_per_symbol'],
            'user_id' => $options['user_id'],
            'ip_address' => $options['ip_address'],
            'timestamp' => $response->timestamp->format('Y-m-d H:i:s'),
            'status' => $response->status === 'success' ? 'completed' : ($response->status === 'partial_success' ? 'completed_with_errors' : 'error'),
        ];

        return [
            'summary' => $summary,
            'details' => $details,
            'errors' => $response->errors,
        ];
    }

    /**
     * @param string[] $symbols
     * @param array{dry_run: bool, force_run: bool, current_tf: ?string, force_timeframe_check: bool, auto_switch_invalid: bool, switch_duration: string} $options
     * @return array{summary: array, details: array, errors: array}
     */
    private function runInParallel(SymfonyStyle $io, array $symbols, int $workers, array $options): array
    {
        $queue = new SplQueue();
        foreach ($symbols as $symbol) {
            $queue->enqueue($symbol);
        }

        $active = [];
        $results = [];
        $errors = [];
        $total = count($symbols);
        $completed = 0;
        $startTime = microtime(true);

        while (!$queue->isEmpty() || !empty($active)) {
            while (count($active) < $workers && !$queue->isEmpty()) {
                $symbol = $queue->dequeue();
                $process = new Process(
                    $this->buildWorkerCommand($symbol, $options),
                    $this->projectDir,
                    // Forcer l'affichage des traces pour diagnostiquer les erreurs des workers
                    ['APP_DEBUG' => '1']
                );
                $process->start();
                $active[] = ['symbol' => $symbol, 'process' => $process];
            }

            foreach ($active as $index => $worker) {
                $process = $worker['process'];
                if ($process->isRunning()) {
                    continue;
                }

                $symbol = $worker['symbol'];
                unset($active[$index]);
                $active = array_values($active);

                if ($process->isSuccessful()) {
                    $rawOutput = trim($process->getOutput());
                    if ($rawOutput === '') {
                        $errors[] = sprintf('Worker %s: sortie vide.', $symbol);
                        continue;
                    }

                    try {
                        $payload = json_decode($rawOutput, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException $exception) {
                        $jsonStart = strpos($rawOutput, '{');
                        if ($jsonStart === false) {
                            $errors[] = sprintf('Worker %s: sortie JSON invalide (%s).', $symbol, $exception->getMessage());
                            continue;
                        }

                        $candidate = substr($rawOutput, $jsonStart);
                        $jsonEnd = strrpos($candidate, '}');
                        if ($jsonEnd !== false) {
                            $candidate = substr($candidate, 0, $jsonEnd + 1);
                        }

                        try {
                            $payload = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
                        } catch (JsonException $exception2) {
                            $errors[] = sprintf('Worker %s: sortie JSON invalide (%s).', $symbol, $exception2->getMessage());
                            continue;
                        }
                    }

                    $final = $payload['final'] ?? null;
                    if (!is_array($final)) {
                        $errors[] = sprintf('Worker %s: résultat final manquant.', $symbol);
                        continue;
                    }

                    $workerResults = isset($final['results']) && is_array($final['results']) ? $final['results'] : [];
                    if (empty($workerResults)) {
                        $errors[] = sprintf('Worker %s: aucun symbole traité.', $symbol);
                    }

                    foreach ($workerResults as $resultSymbol => $info) {
                        // Ignorer l'entrée synthétique "FINAL" renvoyée par le worker
                        if ($resultSymbol === 'FINAL') {
                            continue;
                        }

                        $results[$resultSymbol] = $info;
                        $completed++;
                        $percentage = $total > 0 ? round(($completed / $total) * 100, 2) : 100.0;
                        $status = $info['status'] ?? 'unknown';
                        $io->writeln(sprintf('[%s%%] %s - %s', $percentage, $resultSymbol, $status));
                    }
                } else {
                    $stderr = trim($process->getErrorOutput());
                    $stdout = trim($process->getOutput());
                    $message = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'échec inconnu');
                    $errors[] = sprintf('Worker %s: %s', $symbol, $message);
                }
            }

            usleep(100_000);
        }

        $processed = count($results);
        $successCount = count(array_filter($results, function ($r) {
            $s = strtoupper((string)($r['status'] ?? ''));
            return in_array($s, ['SUCCESS', 'COMPLETED', 'READY'], true);
        }));
        $failedCount = count(array_filter($results, function ($r) {
            $s = strtoupper((string)($r['status'] ?? ''));
            return in_array($s, ['ERROR', 'INVALID'], true);
        }));
        $skippedCount = count(array_filter($results, function ($r) {
            $td = $r['trading_decision']['status'] ?? null;
            if (is_string($td) && strtolower($td) === 'skipped') { return true; }
            $s = strtoupper((string)($r['status'] ?? ''));
            return in_array($s, ['SKIPPED', 'GRACE_WINDOW'], true);
        }));

        $summary = [
            'run_id' => Uuid::uuid4()->toString(),
            'execution_time_seconds' => round(microtime(true) - $startTime, 3),
            'symbols_requested' => $total,
            'symbols_processed' => $processed,
            'symbols_successful' => $successCount,
            'symbols_failed' => $failedCount,
            'symbols_skipped' => $skippedCount,
            'success_rate' => $processed > 0 ? round(($successCount / $processed) * 100, 2) : 0.0,
            'dry_run' => $options['dry_run'],
            'force_run' => $options['force_run'],
            'current_tf' => $options['current_tf'],
            'lock_per_symbol' => $options['lock_per_symbol'],
            'user_id' => $options['user_id'],
            'ip_address' => $options['ip_address'],
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => empty($errors) ? 'completed' : 'completed_with_errors',
        ];

        return [
            'summary' => $summary,
            'details' => $results,
            'errors' => $errors,
        ];
    }

    private function renderFinalReport(SymfonyStyle $io, array $summary, array $details, float $commandStart): void
    {
        $totalExecutionTime = microtime(true) - $commandStart;
        $formattedDuration = $this->formatDuration($totalExecutionTime);
        $serviceExecutionTime = isset($summary['execution_time_seconds']) ? (float) $summary['execution_time_seconds'] : 0.0;
        $formattedServiceDuration = $this->formatDuration($serviceExecutionTime);

        $io->section('Résumé');
        $io->definitionList(
            ['Run ID' => $summary['run_id'] ?? '-'],
            ['Statut' => $summary['status'] ?? '-'],
            ['Durée totale d\'exécution' => $formattedDuration],
            ['Durée service MTF' => $formattedServiceDuration],
            ['Demandés' => (string) ($summary['symbols_requested'] ?? '-')],
            ['Traités' => (string) ($summary['symbols_processed'] ?? '-')],
            ['Succès' => (string) ($summary['symbols_successful'] ?? '-')],
            ['Échecs' => (string) ($summary['symbols_failed'] ?? '-')],
            ['Ignorés' => (string) ($summary['symbols_skipped'] ?? '-')],
            ['Taux de succès (%)' => (string) ($summary['success_rate'] ?? '-')],
            ['Dry run' => ($summary['dry_run'] ?? true) ? 'oui' : 'non'],
            ['Force run' => ($summary['force_run'] ?? false) ? 'oui' : 'non'],
            ['TF courant' => $summary['current_tf'] ?? '(multi-tf)'],
            ['Timestamp' => $summary['timestamp'] ?? '-'],
        );

        if (!empty($details)) {
            $io->section('Détails par symbole');
            foreach ($details as $symbol => $info) {
                $status = $info['status'] ?? '-';
                $io->writeln(sprintf('<info>%s</info> - statut: <comment>%s</comment>', $symbol, $status));

                if (isset($info['reason'])) {
                    $io->writeln(sprintf('  Raison: %s', (string) $info['reason']));
                }

                if (isset($info['blocking_tf'])) {
                    $io->writeln(sprintf('  Rejeté sur TF: %s', (string) $info['blocking_tf']));
                }

                $failedLong = (array)($info['failed_conditions_long'] ?? []);
                $failedShort = (array)($info['failed_conditions_short'] ?? []);
                if (!empty($failedLong) || !empty($failedShort)) {
                    $io->writeln('  Conditions échouées:');
                    if (!empty($failedLong)) {
                        $io->writeln('    - LONG: ' . implode(', ', array_map('strval', $failedLong)));
                    }
                    if (!empty($failedShort)) {
                        $io->writeln('    - SHORT: ' . implode(', ', array_map('strval', $failedShort)));
                    }
                }

                if (isset($info['error'])) {
                    $io->writeln(sprintf('  Erreur: %s', (string) $info['error']));
                }

                if (isset($info['signal_side'])) {
                    $io->writeln(sprintf('  Signal side: %s', (string) $info['signal_side']));
                }

                if (isset($info['timeframe'])) {
                    $io->writeln(sprintf('  TF: %s', (string) $info['timeframe']));
                }

                // Afficher le dernier TF atteint (arrêt)
                $lastTf = null;
                if (isset($info['blocking_tf']) && is_string($info['blocking_tf'])) {
                    $lastTf = (string)$info['blocking_tf'];
                } elseif (isset($info['execution_tf']) && is_string($info['execution_tf'])) {
                    $lastTf = (string)$info['execution_tf'];
                } elseif (isset($info['steps']) && is_array($info['steps'])) {
                    foreach (['1m','5m','15m','1h','4h'] as $tfOrderDesc) {
                        if (isset($info['steps'][$tfOrderDesc])) { $lastTf = $tfOrderDesc; break; }
                    }
                }
                if ($lastTf !== null) {
                    $io->writeln(sprintf('  Last TF: %s', $lastTf));
                }

                if (array_key_exists('should_descend', $info)) {
                    $io->writeln(sprintf('  Should descend: %s', $info['should_descend'] ? 'oui' : 'non'));
                }

                if (isset($info['next_tf'])) {
                    $io->writeln(sprintf('  Next TF: %s', (string) ($info['next_tf'] ?? '-')));
                }

                if (isset($info['validation_state']) && is_array($info['validation_state'])) {
                    $io->writeln('  Validation:');
                    $io->writeln(sprintf('    - status: %s', (string) ($info['validation_state']['status'] ?? '-')));
                }

                if (isset($info['steps']) && is_array($info['steps'])) {
                    $io->writeln('  Étapes:');
                    foreach (['4h','1h','15m','5m','1m'] as $tf) {
                        if (!isset($info['steps'][$tf])) {
                            continue;
                        }
                        $step = $info['steps'][$tf];
                        $s = $step['status'] ?? '-';
                        $rs = $step['reason'] ?? '';
                        $kt = isset($step['kline_time']) && $step['kline_time'] instanceof \DateTimeImmutable ? $step['kline_time']->format('Y-m-d H:i:s') : ($step['kline_time'] ?? '-');
                        $io->writeln(sprintf('    - %s: %s%s%s', $tf, $s, $rs ? " (" . $rs . ")" : '', $kt ? " | kline_time=" . $kt : ''));
                    }
                }

                if (isset($info['filters']) && is_array($info['filters'])) {
                    $io->writeln('  Filtres d\'exécution:');
                    $io->writeln(sprintf('    - statut: %s', (string) ($info['filters']['status'] ?? '-')));
                    if (!empty($info['filters']['details'])) {
                        $io->writeln('    - détails:');
                        foreach ((array) $info['filters']['details'] as $k => $v) {
                            $io->writeln(sprintf('        %s: %s', (string) $k, is_scalar($v) ? (string) $v : json_encode($v)));
                        }
                    }
                }

                $io->newLine();
            }

            $this->displaySummaryByStatus($io, $details);
            $this->displaySummaryByReason($io, $details);
            $this->displaySummaryByRejectedTimeframe($io, $details);
            $this->displaySummaryByLastValidTimeframe($io, $details);
            $this->displaySummaryByLastTimeframe($io, $details);

            // Afficher les ordres placés
            $this->displayPlacedOrders($io, $details);
        }
    }

    /**
     * @param array{dry_run: bool, force_run: bool, current_tf: ?string, force_timeframe_check: bool, auto_switch_invalid: bool, switch_duration: string, exchange?: string, market_type?: string, profile?: ?string, validation_mode?: ?string} $options
     */
    private function buildWorkerCommand(string $symbol, array $options): array
    {
        $command = [
            PHP_BINARY,
            $this->projectDir . '/bin/console',
            'mtf:run-worker',
            '--symbols=' . $symbol,
            '--dry-run=' . ($options['dry_run'] ? '1' : '0'),
            '--switch-duration=' . $options['switch_duration'],
            '-vvv',
        ];

        if ($options['force_run']) {
            $command[] = '--force-run';
        }
        if ($options['current_tf']) {
            $command[] = '--tf=' . $options['current_tf'];
        }
        if ($options['force_timeframe_check']) {
            $command[] = '--force-timeframe-check';
        }
        if ($options['auto_switch_invalid']) {
            $command[] = '--auto-switch-invalid';
        }
        if (!empty($options['skip_context'])) {
            $command[] = '--skip-context';
        }
        if (!empty($options['lock_per_symbol'])) {
            $command[] = '--lock-per-symbol';
        }
        if (!empty($options['user_id'])) {
            $command[] = '--user-id=' . $options['user_id'];
        }
        if (!empty($options['ip_address'])) {
            $command[] = '--ip-address=' . $options['ip_address'];
        }

        if (!empty($options['skip_open_filter'])) {
            $command[] = '--skip-open-filter';
        }

        if (!empty($options['exchange'])) {
            $command[] = '--exchange=' . $options['exchange'];
        }
        if (!empty($options['market_type'])) {
            $command[] = '--market-type=' . $options['market_type'];
        }
        if (!empty($options['profile'])) {
            $command[] = '--trade-profile=' . $options['profile'];
        }
        if (!empty($options['validation_mode'])) {
            $command[] = '--validation-mode=' . $options['validation_mode'];
        }

        return $command;
    }

    /**
     * Affiche un résumé groupé par statut
     */
    private function displaySummaryByStatus(SymfonyStyle $io, array $details): void
    {
        $statusGroups = [];

        foreach ($details as $symbol => $info) {
            $status = $info['status'] ?? 'UNKNOWN';
            if (!isset($statusGroups[$status])) {
                $statusGroups[$status] = [];
            }
            $statusGroups[$status][] = $symbol;
        }

        if (!empty($statusGroups)) {
            $io->section('Résumé par statut');
            foreach ($statusGroups as $status => $symbols) {
                $io->writeln(sprintf('<comment>%s</comment>: %s', $status, implode(', ', $symbols)));
            }
            $io->newLine();
        }
    }

    /**
     * Affiche un résumé groupé par raison
     */
    private function displaySummaryByReason(SymfonyStyle $io, array $details): void
    {
        $reasonGroups = [];

        foreach ($details as $symbol => $info) {
            $reason = $info['reason'] ?? 'N/A';
            if (!isset($reasonGroups[$reason])) {
                $reasonGroups[$reason] = [];
            }
            $reasonGroups[$reason][] = $symbol;
        }

        if (!empty($reasonGroups)) {
            $io->section('Résumé par raison');
            foreach ($reasonGroups as $reason => $symbols) {
                $io->writeln(sprintf('<comment>%s</comment>: %s', $reason, implode(', ', $symbols)));
            }
            $io->newLine();
        }
    }

    /**
     * Affiche un résumé groupé par timeframe rejeté
     */
    private function displaySummaryByRejectedTimeframe(SymfonyStyle $io, array $details): void
    {
        $rejectedTfGroups = [];

        foreach ($details as $symbol => $info) {
            $rejectedTf = $info['blocking_tf'] ?? null;
            if ($rejectedTf) {
                if (!isset($rejectedTfGroups[$rejectedTf])) {
                    $rejectedTfGroups[$rejectedTf] = [];
                }
                $rejectedTfGroups[$rejectedTf][] = $symbol;
            }
        }

        if (!empty($rejectedTfGroups)) {
            $io->section('Résumé par timeframe rejeté');
            foreach ($rejectedTfGroups as $tf => $symbols) {
                $io->writeln(sprintf('<comment>%s</comment>: %s', $tf, implode(', ', $symbols)));
            }
            $io->newLine();
        }
    }

    /**
     * Affiche un résumé groupé par dernier timeframe validé
     */
    private function displaySummaryByLastValidTimeframe(SymfonyStyle $io, array $details): void
    {
        $lastValidTfGroups = [];

        foreach ($details as $symbol => $info) {
            $lastValidTf = $this->getLastValidTimeframe($info);
            if ($lastValidTf) {
                if (!isset($lastValidTfGroups[$lastValidTf])) {
                    $lastValidTfGroups[$lastValidTf] = [];
                }
                $lastValidTfGroups[$lastValidTf][] = $symbol;
            }
        }

        if (!empty($lastValidTfGroups)) {
            $io->section('Résumé par dernier TF validé');
            // Trier par ordre décroissant des timeframes (4h, 1h, 15m, 5m, 1m)
            $tfOrder = ['4h' => 5, '1h' => 4, '15m' => 3, '5m' => 2, '1m' => 1];
            uksort($lastValidTfGroups, function($a, $b) use ($tfOrder) {
                return ($tfOrder[$b] ?? 0) - ($tfOrder[$a] ?? 0);
            });

            foreach ($lastValidTfGroups as $tf => $symbols) {
                $io->writeln(sprintf('<comment>%s</comment>: %s', $tf, implode(', ', $symbols)));
            }
            $io->newLine();
        }
    }

    /**
     * Affiche un résumé groupé par dernier TF atteint (READY: 1m; INVALID: TF d'arrêt si disponible; sinon N/A)
     */
    private function displaySummaryByLastTimeframe(SymfonyStyle $io, array $details): void
    {
        $groups = [];

        foreach ($details as $symbol => $info) {
            $lastTf = $this->determineLastTimeframe($info);
            $key = $lastTf ?? 'N/A';
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $symbol;
        }

        if (!empty($groups)) {
            $io->section('Résumé par TF');
            $tfOrder = ['4h' => 5, '1h' => 4, '15m' => 3, '5m' => 2, '1m' => 1, 'N/A' => 0];
            uksort($groups, function($a, $b) use ($tfOrder) {
                return ($tfOrder[$b] ?? 0) - ($tfOrder[$a] ?? 0);
            });

            foreach ($groups as $tf => $symbols) {
                $io->writeln(sprintf('<comment>%s</comment>: %s', $tf, implode(', ', $symbols)));
            }
            $io->newLine();
        }
    }

    private function determineLastTimeframe(array $info): ?string
    {
        // Priorité: blocking_tf si présent (indique l'arrêt)
        if (isset($info['blocking_tf']) && is_string($info['blocking_tf'])) {
            return (string) $info['blocking_tf'];
        }
        // Sinon, essayer d'inférer via les steps connues, du plus bas au plus haut atteint
        if (isset($info['steps']) && is_array($info['steps'])) {
            foreach (['1m','5m','15m','1h','4h'] as $tfAsc) {
                if (isset($info['steps'][$tfAsc])) {
                    $step = $info['steps'][$tfAsc];
                    $status = strtolower((string)($step['status'] ?? ''));
                    if (in_array($status, ['success','valid','completed','ready'])) {
                        // continuer à chercher plus bas (plus proche de 1m)
                        continue;
                    }
                }
            }
            // Si steps existe, prendre le plus bas présent comme dernier atteint
            foreach (['1m','5m','15m','1h','4h'] as $tfAsc) {
                if (isset($info['steps'][$tfAsc])) {
                    return $tfAsc;
                }
            }
        }
        // READY sans steps détaillés: par convention, 1m
        if (isset($info['status']) && strtoupper((string)$info['status']) === 'READY') {
            return '1m';
        }
        return null;
    }

    /**
     * Affiche une section dédiée pour les ordres placés
     */
    private function displayPlacedOrders(SymfonyStyle $io, array $details): void
    {
        $ordersPlaced = OrdersExtractor::extractPlacedOrders($details);
        $ordersCount = OrdersExtractor::countOrdersByStatus($details);

        if (empty($ordersPlaced)) {
            $io->section('Ordres placés');
            $io->text('Aucun ordre placé lors de cette exécution.');
            $io->newLine();
            return;
        }

        $io->section('Ordres placés');
        $io->definitionList(
            ['Total' => (string) $ordersCount['total']],
            ['Soumis (réels)' => (string) $ordersCount['submitted']],
            ['Simulés (dry-run)' => (string) $ordersCount['simulated']],
        );

        $io->writeln('<comment>Détails des ordres:</comment>');
        foreach ($ordersPlaced as $order) {
            $statusLabel = $order['status'] === 'submitted' ? '<fg=green>SOUMIS</>' : '<fg=yellow>SIMULÉ</>';
            $io->writeln(sprintf(
                '  <info>%s</info> - %s',
                $order['symbol'],
                $statusLabel
            ));

            if ($order['client_order_id'] !== null) {
                $io->writeln(sprintf('    Client Order ID: %s', $order['client_order_id']));
            }

            if ($order['exchange_order_id'] !== null) {
                $io->writeln(sprintf('    Exchange Order ID: %s', $order['exchange_order_id']));
            }

            if ($order['decision_key'] !== null) {
                $io->writeln(sprintf('    Decision Key: %s', $order['decision_key']));
            }

            if (!empty($order['raw'])) {
                $io->writeln('    Détails bruts (raw):');
                foreach ($order['raw'] as $key => $value) {
                    if (is_scalar($value)) {
                        $io->writeln(sprintf('      %s: %s', $key, (string) $value));
                    } elseif (is_array($value)) {
                        $io->writeln(sprintf('      %s: %s', $key, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)));
                    } else {
                        $io->writeln(sprintf('      %s: %s', $key, gettype($value)));
                    }
                }
            }

            $io->newLine();
        }
    }

    /**
     * Détermine le dernier timeframe validé pour un symbole
     */
    private function getLastValidTimeframe(array $info): ?string
    {
        if (!isset($info['steps']) || !is_array($info['steps'])) {
            return null;
        }

        $timeframes = ['4h', '1h', '15m', '5m', '1m'];

        foreach ($timeframes as $tf) {
            if (isset($info['steps'][$tf])) {
                $step = $info['steps'][$tf];
                $status = $step['status'] ?? '';

                // Si le statut est 'success' ou 'valid', c'est le dernier TF validé
                if (in_array($status, ['success', 'valid', 'completed'])) {
                    return $tf;
                }
            }
        }

        return null;
    }

    /**
     * Formate une durée en secondes en format lisible (minutes:secondes ou heures:minutes:secondes)
     */
    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.2f secondes', $seconds);
        }

        $minutes = (int) floor($seconds / 60);
        $remainingSeconds = fmod($seconds, 60.0);

        if ($minutes < 60) {
            return sprintf('%d min %.1f sec', $minutes, $remainingSeconds);
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%d h %d min %.1f sec', $hours, $remainingMinutes, $remainingSeconds);
    }

    /**
     * Traite automatiquement les symboles INVALID en les ajoutant à mtf_switch
     */
    private function processInvalidSymbols(SymfonyStyle $io, array $details, string $duration): void
    {
        $invalidSymbols = [];

        foreach ($details as $symbol => $info) {
            $status = $info['status'] ?? '';
            if ($status === 'INVALID') {
                $invalidSymbols[] = $symbol;
            }
        }

        if (empty($invalidSymbols)) {
            $io->section('Traitement automatique des symboles INVALID');
            $io->text('Aucun symbole INVALID trouvé - aucun switch à créer');
            return;
        }

        $io->section('Traitement automatique des symboles INVALID');
        $io->text(sprintf('Symboles INVALID trouvés: %d', count($invalidSymbols)));
        $io->listing($invalidSymbols);

        if (!$this->isValidDuration($duration)) {
            $io->error(sprintf('Format de durée invalide: %s. Utilisez des formats comme 4h, 1d, 1w', $duration));
            return;
        }

        $expiresAt = $this->parseDuration($duration);
        $reason = 'INVALID_SIGNAL_AUTO';
        $description = "Désactivé automatiquement après mtf:run - $reason";

        $updatedCount = 0;
        foreach ($invalidSymbols as $symbol) {
            try {
                $this->createOrUpdateSwitch($symbol, $expiresAt, $reason, $description);
                $updatedCount++;
                $io->text("✓ $symbol ajouté au switch");
            } catch (\Exception $e) {
                $io->error("✗ Erreur pour $symbol: " . $e->getMessage());
            }
        }

        if ($updatedCount > 0) {
            $io->success(sprintf(
                "%d symboles INVALID ajoutés à mtf_switch (désactivés jusqu'au %s)",
                $updatedCount,
                $expiresAt->format('Y-m-d H:i:s')
            ));
        }
    }

    /**
     * Valide le format de durée
     */
    private function isValidDuration(string $duration): bool
    {
        return preg_match('/^\d+[hmdw]$/', $duration) === 1;
    }

    /**
     * Parse une durée en DateTimeImmutable
     */
    private function parseDuration(string $duration): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $matches = [];
        if (preg_match('/^(\d+)([hmdw])$/', $duration, $matches)) {
            $value = (int) $matches[1];
            $unit = $matches[2];

            return match ($unit) {
                'm' => $now->modify("+{$value} minutes"),
                'h' => $now->modify("+{$value} hours"),
                'd' => $now->modify("+{$value} days"),
                'w' => $now->modify("+{$value} weeks"),
                default => throw new \InvalidArgumentException("Unité de temps non supportée: $unit")
            };
        }

        throw new \InvalidArgumentException("Format de durée invalide: $duration");
    }

    /**
     * Crée ou met à jour un switch pour un symbole
     */
    private function createOrUpdateSwitch(string $symbol, \DateTimeImmutable $expiresAt, string $reason, string $description): void
    {
        $switchKey = "SYMBOL:{$symbol}";
        $existingSwitch = $this->mtfSwitchRepository->findOneBy(['switchKey' => $switchKey]);

        if ($existingSwitch) {
            // Mettre à jour le switch existant
            $existingSwitch->setIsOn(false);
            $existingSwitch->setDescription($description);
            $existingSwitch->setExpiresAt($expiresAt);
            $existingSwitch->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } else {
            // Créer un nouveau switch
            $switch = MtfSwitch::createSymbolSwitch($symbol);
            $switch->setIsOn(false);
            $switch->setDescription($description);
            $switch->setExpiresAt($expiresAt);
            $this->entityManager->persist($switch);
        }

        $this->entityManager->flush();
    }

    /**
     * Convertit une erreur (tableau ou string) en chaîne de caractères pour l'affichage
     */
    private function formatErrorForDisplay(mixed $error): ?string
    {
        if ($error === null) {
            return null;
        }

        if (is_array($error)) {
            return json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return (string) $error;
    }

    /**
     * Filtre les symboles ayant des ordres ou positions ouverts
     * Cette méthode est appelée AVANT le traitement des workers pour exclure ces symboles
     *
     * @param array<string> $symbols Liste des symboles à filtrer
     * @param string $runIdString ID du run pour les logs
     * @param array<string> $excludedSymbols Référence pour retourner les symboles exclus
     * @return array<string> Liste des symboles à traiter (sans ceux exclus)
     */
    private function filterSymbolsWithOpenOrdersOrPositions(array $symbols, string $runIdString, array &$excludedSymbols = [], ?MainProviderInterface $provider = null): array
    {
        $excludedSymbols = [];
        $provider ??= $this->mainProvider;

        if (empty($symbols) || (!$provider?->getAccountProvider() && !$provider?->getOrderProvider())) {
            return $symbols;
        }

        $symbolsToProcess = [];

        // Récupérer les symboles avec positions ouvertes depuis l'exchange
        $openPositionSymbols = [];
        $accountProvider = $provider?->getAccountProvider();
        if ($accountProvider) {
            try {
                $openPositions = $accountProvider->getOpenPositions();

                foreach ($openPositions as $position) {
                    $positionSymbol = strtoupper($position->symbol ?? '');
                    if ($positionSymbol !== '' && !in_array($positionSymbol, $openPositionSymbols, true)) {
                        $openPositionSymbols[] = $positionSymbol;
                    }
                }
            } catch (\Throwable $e) {
                // Log silencieux en CLI, on continue avec les symboles disponibles
            }
        }

        // Récupérer les symboles avec ordres ouverts depuis l'exchange
        $openOrderSymbols = [];
        $orderProvider = $provider?->getOrderProvider();
        if ($orderProvider) {
            try {
                $openOrders = $orderProvider->getOpenOrders();

                foreach ($openOrders as $order) {
                    $orderSymbol = strtoupper($order->symbol ?? '');
                    if ($orderSymbol !== '' && !in_array($orderSymbol, $openOrderSymbols, true)) {
                        $openOrderSymbols[] = $orderSymbol;
                    }
                }
            } catch (\Throwable $e) {
                // Log silencieux en CLI, on continue avec les symboles disponibles
            }
        }

        // Combiner les symboles à exclure
        $symbolsWithActivity = array_unique(array_merge($openPositionSymbols, $openOrderSymbols));

        // Réactiver les switches des symboles qui n'ont plus d'ordres/positions ouverts
        try {
            $reactivatedCount = $this->mtfSwitchRepository->reactivateSwitchesForInactiveSymbols($symbolsWithActivity);
            if ($reactivatedCount > 0) {
                // Log silencieux en CLI, mais on peut ajouter un log si nécessaire
            }
        } catch (\Throwable $e) {
            // Log silencieux en CLI pour les erreurs de switch
        }

        // Filtrer les symboles
        foreach ($symbols as $symbol) {
            $symbolUpper = strtoupper($symbol);

            if (in_array($symbolUpper, $symbolsWithActivity, true)) {
                $excludedSymbols[] = $symbolUpper;
            } else {
                $symbolsToProcess[] = $symbol;
            }
        }

        return $symbolsToProcess;
    }

    /**
     * Met à jour les switches pour les symboles exclus (appelé APRÈS le traitement)
     *
     * @param array<string> $excludedSymbols Liste des symboles exclus (avec ordres/positions ouverts)
     * @param string $runIdString ID du run pour les logs
     */
    private function updateSwitchesForExcludedSymbols(array $excludedSymbols, string $runIdString): void
    {
        // Mettre à jour les switches pour les symboles exclus
        foreach ($excludedSymbols as $symbolUpper) {
            try {
                $isSwitchOff = !$this->mtfSwitchRepository->isSymbolSwitchOn($symbolUpper);

                if ($isSwitchOff) {
                    $this->mtfSwitchRepository->turnOffSymbolForDuration($symbolUpper, '1m');
                } else {
                    $this->mtfSwitchRepository->turnOffSymbolForDuration($symbolUpper, duration: '5m');
                }
            } catch (\Throwable $e) {
                // Log silencieux en CLI pour les erreurs de switch
            }
        }
    }
}
