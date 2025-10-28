<?php

declare(strict_types=1);

namespace App\Command\Mtf;

use App\MtfValidator\Service\MtfRunService;
use App\Entity\MtfSwitch;
use App\Provider\Bitmart\Http\BitmartHttpClientPublic;
use App\Repository\ContractRepository;
use App\Repository\MtfSwitchRepository;
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

#[AsCommand(name: 'mtf:run', description: 'Exécute un cycle MTF pour une liste de symboles (dry-run par défaut) avec un output détaillé')]
class MtfRunCommand extends Command
{
    public function __construct(
        private readonly MtfRunService $mtfRunService,
        private readonly BitmartHttpClientPublic $bitmartClient,
        private readonly ContractRepository $contractRepository,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly EntityManagerInterface $entityManager,
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
            ->addOption('auto-switch-invalid', null, InputOption::VALUE_NONE, 'Ajoute automatiquement les symboles INVALID à mtf_switch après l\'exécution')
            ->addOption('switch-duration', null, InputOption::VALUE_OPTIONAL, 'Durée de désactivation pour les symboles INVALID (ex: 4h, 1d, 1w)', '1d')
            ->addOption('workers', null, InputOption::VALUE_OPTIONAL, 'Nombre de workers parallèles (1 = mode séquentiel)', '1');
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
        $autoSwitchInvalid = (bool) $input->getOption('auto-switch-invalid');
        $switchDuration = (string) $input->getOption('switch-duration');
        $workers = max(1, (int) $input->getOption('workers'));

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
            sprintf('- workers: %d', $workers),
        ]);

        $io->note(sprintf('Démarrage de l\'exécution MTF à %s', date('Y-m-d H:i:s')));

        try {
            $shouldSyncContracts = $syncContractsOpt || empty($symbols);

            if ($shouldSyncContracts) {
                $io->section('Synchronisation des contrats (BitMart)');
                try {
                    $allContracts = $this->bitmartClient->fetchContracts();
                    $fetchedContracts = [];

                    if (!empty($symbols)) {
                        $indexedContracts = [];
                        foreach ($allContracts as $contract) {
                            if (!isset($contract['symbol'])) {
                                continue;
                            }
                            $indexedContracts[strtoupper((string) $contract['symbol'])] = $contract;
                        }

                        foreach ($symbols as $s) {
                            $key = strtoupper($s);
                            if (!isset($indexedContracts[$key])) {
                                throw new \RuntimeException(sprintf('Contract not found for symbol %s', $s));
                            }
                            $fetchedContracts[] = $indexedContracts[$key];
                        }
                    } else {
                        $fetchedContracts = $allContracts;
                    }

                    $upserted = 0;
                    if (!empty($fetchedContracts)) {
                        $upserted = $this->contractRepository->upsertContracts($fetchedContracts);
                        $io->success(sprintf('%d contrat(s) synchronisé(s) (upsert)', $upserted));
                    } else {
                        $io->warning('Aucun contrat récupéré depuis l\'API BitMart');
                    }

                    if (empty($symbols)) {
                        $symbols = $this->contractRepository->allActiveSymbolNames();
                    }
                } catch (\Throwable $e) {
                    $io->warning('Synchronisation des contrats échouée: ' . $e->getMessage());
                }
            } else {
                $io->section('Synchronisation des contrats (BitMart)');
                $io->writeln('Ignorée (liste de symboles fournie sans --sync-contracts).');
            }

            if (empty($symbols)) {
                $symbols = $this->contractRepository->allActiveSymbolNames();
            }
            $symbols = array_slice($symbols, 0, 10);

            if (empty($symbols)) {
                $io->warning('Aucun symbole actif trouvé');
                return Command::SUCCESS;
            }

            $executionOptions = [
                'dry_run' => $dryRun,
                'force_run' => $forceRun,
                'current_tf' => $currentTf,
                'force_timeframe_check' => $forceTimeframeCheck,
                'auto_switch_invalid' => $autoSwitchInvalid,
                'switch_duration' => $switchDuration,
            ];

            $io->section($workers > 1 ? sprintf('Exécution MTF en parallèle (%d workers)', $workers) : 'Exécution MTF en cours...');

            $result = $workers > 1
                ? $this->runInParallel($io, $symbols, $workers, $executionOptions)
                : $this->runSequential($io, $symbols, $executionOptions);

            $summary = $result['summary'] ?? [];
            $details = $result['details'] ?? [];
            $errors = $result['errors'] ?? [];

            $this->renderFinalReport($io, $summary, $details, $commandStart);

            if ($autoSwitchInvalid && !$dryRun) {
                $this->processInvalidSymbols($io, $details, $switchDuration);
            }

            if (!empty($errors)) {
                foreach ($errors as $message) {
                    $io->warning($message);
                }
                $io->warning('MTF run terminé avec des erreurs.');
                return Command::FAILURE;
            }

            $io->success(sprintf('MTF run terminé en %s', $this->formatDuration(microtime(true) - $commandStart)));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Erreur après %s: %s', $this->formatDuration(microtime(true) - $commandStart), $e->getMessage()));
            return Command::FAILURE;
        }
    }

    /**
     * @param string[] $symbols
     * @param array{dry_run: bool, force_run: bool, current_tf: ?string, force_timeframe_check: bool, auto_switch_invalid: bool, switch_duration: string} $options
     * @return array{summary: array, details: array, errors: array}
     */
    private function runSequential(SymfonyStyle $io, array $symbols, array $options): array
    {
        $generator = $this->mtfRunService->run(
            $symbols,
            $options['dry_run'],
            $options['force_run'],
            $options['current_tf'],
            $options['force_timeframe_check']
        );

        $details = [];
        $summary = [];

        foreach ($generator as $yieldedData) {
            $symbol = $yieldedData['symbol'];
            $result = $yieldedData['result'];
            $progress = $yieldedData['progress'];

            if ($symbol === 'FINAL') {
                $summary = $result;
                continue;
            }

            $io->writeln(sprintf('[%s%%] %s - %s', $progress['percentage'], $symbol, $progress['status']));
            $details[$symbol] = $result;
        }

        try {
            $finalResult = $generator->getReturn();
            if (is_array($finalResult)) {
                if (isset($finalResult['summary']) && is_array($finalResult['summary'])) {
                    $summary = $finalResult['summary'];
                }
                if (isset($finalResult['results']) && is_array($finalResult['results'])) {
                    $details = $finalResult['results'];
                }
            }
        } catch (\Throwable $e) {
            $io->writeln('Note: Impossible de récupérer le résultat final du generator');
        }

        return [
            'summary' => $summary,
            'details' => $details,
            'errors' => [],
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
                    $this->projectDir
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
                        $errors[] = sprintf('Worker %s: sortie JSON invalide (%s).', $symbol, $exception->getMessage());
                        continue;
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
                        $results[$resultSymbol] = $info;
                        $completed++;
                        $percentage = $total > 0 ? round(($completed / $total) * 100, 2) : 100.0;
                        $status = $info['status'] ?? 'unknown';
                        $io->writeln(sprintf('[%s%%] %s - %s', $percentage, $resultSymbol, $status));
                    }
                } else {
                    $errors[] = sprintf(
                        'Worker %s: %s',
                        $symbol,
                        ($errorOutput = trim($process->getErrorOutput())) !== '' ? $errorOutput : 'échec inconnu'
                    );
                }
            }

            usleep(100_000);
        }

        $processed = count($results);
        $successCount = count(array_filter($results, fn($r) => strtoupper((string)($r['status'] ?? '')) === 'SUCCESS'));
        $failedCount = count(array_filter($results, fn($r) => strtoupper((string)($r['status'] ?? '')) === 'ERROR'));
        $skippedCount = count(array_filter($results, fn($r) => strtoupper((string)($r['status'] ?? '')) === 'SKIPPED'));

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

                if (isset($info['failed_timeframe'])) {
                    $io->writeln(sprintf('  Rejeté sur TF: %s', (string) $info['failed_timeframe']));
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
        }
    }

    /**
     * @param array{dry_run: bool, force_run: bool, current_tf: ?string, force_timeframe_check: bool, auto_switch_invalid: bool, switch_duration: string} $options
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
            $rejectedTf = $info['failed_timeframe'] ?? null;
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
}
