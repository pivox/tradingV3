<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Common\Enum\Timeframe;
use App\Config\MtfConfigProviderInterface;
use App\Entity\Contract;
use App\Entity\MtfAudit;
use App\Event\MtfAuditEvent;
use App\Contract\Provider\ProviderInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Provider\Bitmart\Dto\KlineDto;
use App\Provider\Bitmart\Http\BitmartHttpClientPublic;
use App\Provider\Bitmart\Service\BitmartKlineProvider;
use App\Provider\Bitmart\Service\KlineJsonIngestionService;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Repository\MtfAuditRepository;
use App\Repository\MtfStateRepository;
use App\Repository\MtfSwitchRepository;
use App\Runtime\Cache\DbValidationCache;
use App\Contract\Signal\SignalValidationServiceInterface;
use App\MtfValidator\Service\Timeframe\Timeframe4hService;
use App\MtfValidator\Service\Timeframe\Timeframe1hService;
use App\MtfValidator\Service\Timeframe\Timeframe15mService;
use App\MtfValidator\Service\Timeframe\Timeframe5mService;
use App\MtfValidator\Service\Timeframe\Timeframe1mService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class MtfService
{
    public function __construct(
        private readonly MtfTimeService $timeService,
        private readonly KlineRepository $klineRepository,
        private readonly MtfStateRepository $mtfStateRepository,
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly MtfAuditRepository $mtfAuditRepository,
        private readonly ContractRepository $contractRepository,
        private readonly SignalValidationServiceInterface $signalValidationService,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $positionsFlowLogger,
        private readonly MtfConfigProviderInterface $mtfConfig,
        private readonly BitmartHttpClientPublic $bitmartClient,
        private readonly KlineProviderInterface $klineProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Timeframe4hService $timeframe4hService,
        private readonly Timeframe1hService $timeframe1hService,
        private readonly Timeframe15mService $timeframe15mService,
        private readonly Timeframe5mService $timeframe5mService,
        private readonly Timeframe1mService $timeframe1mService,
        private readonly ?DbValidationCache $validationCache = null,
        private readonly ?KlineJsonIngestionService $klineJsonIngestion = null,
    ) {
    }

    public function getTimeService(): MtfTimeService
    {
        return $this->timeService;
    }

    /**
     * Exécute le cycle MTF complet pour tous les symboles
     * @return \Generator<int, array{symbol: string, result: array, progress: array}, array>
     */
    public function executeMtfCycle(UuidInterface $runId): \Generator
    {
        $this->logger->info('[MTF] Starting MTF cycle', ['run_id' => $runId->toString()]);

        $results = [];
        $now = $this->timeService->getCurrentAlignedUtc();

        // Vérifier le kill switch global
        if (!$this->mtfSwitchRepository->isGlobalSwitchOn()) {
            $this->logger->warning('[MTF] Global kill switch is OFF, skipping cycle');
            $this->auditStep($runId, 'GLOBAL', 'KILL_SWITCH_OFF', 'Global kill switch is OFF');
            yield [
                'symbol' => 'GLOBAL',
                'result' => ['status' => 'SKIPPED', 'reason' => 'Global kill switch OFF'],
                'progress' => ['current' => 0, 'total' => 0, 'percentage' => 0, 'symbol' => 'GLOBAL', 'status' => 'SKIPPED']
            ];
            return ['status' => 'SKIPPED', 'reason' => 'Global kill switch OFF'];
        }

        // Récupérer tous les symboles actifs depuis la base de données
        $activeSymbols = $this->contractRepository->allActiveSymbolNames();

        if (empty($activeSymbols)) {
            $this->logger->warning('[MTF] No active symbols found');
            $this->auditStep($runId, 'GLOBAL', 'NO_ACTIVE_SYMBOLS', 'No active symbols found');
            yield [
                'symbol' => 'GLOBAL',
                'result' => ['status' => 'SKIPPED', 'reason' => 'No active symbols found'],
                'progress' => ['current' => 0, 'total' => 0, 'percentage' => 0, 'symbol' => 'GLOBAL', 'status' => 'SKIPPED']
            ];
            return ['status' => 'SKIPPED', 'reason' => 'No active symbols found'];
        }

        $this->logger->info('[MTF] Processing symbols', [
            'count' => count($activeSymbols),
            'symbols' => array_slice($activeSymbols, 0, 10) // Log only first 10 for brevity
        ]);

        $totalSymbols = count($activeSymbols);
        foreach ($activeSymbols as $index => $symbol) {
            try {
                // Laisser la logique interne gérer start_from_timeframe (pipeline complet)
                $result = $this->processSymbol($symbol, $runId, $now, null);
                $results[$symbol] = $result;

                // Yield progress information
                $progress = [
                    'current' => $index + 1,
                    'total' => $totalSymbols,
                    'percentage' => round((($index + 1) / $totalSymbols) * 100, 2),
                    'symbol' => $symbol,
                    'status' => $result['status'] ?? 'unknown',
                ];

                yield [
                    'symbol' => $symbol,
                    'result' => $result,
                    'progress' => $progress,
                ];
            } catch (\Exception $e) {
                $this->logger->error('[MTF] Error processing symbol', [
                    'symbol' => $symbol,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $this->auditStep($runId, $symbol, 'ERROR', $e->getMessage());
                $errorResult = ['status' => 'ERROR', 'error' => $e->getMessage()];
                $results[$symbol] = $errorResult;

                // Yield error information
                $progress = [
                    'current' => $index + 1,
                    'total' => $totalSymbols,
                    'percentage' => round((($index + 1) / $totalSymbols) * 100, 2),
                    'symbol' => $symbol,
                    'status' => 'ERROR',
                ];

                yield [
                    'symbol' => $symbol,
                    'result' => $errorResult,
                    'progress' => $progress,
                ];
            }
        }

        $this->logger->info('[MTF] MTF cycle completed', [
            'run_id' => $runId->toString(),
            'results' => $results
        ]);

        // Yield final summary
        yield [
            'symbol' => 'FINAL',
            'result' => [
                'status' => 'COMPLETED',
                'total_symbols' => $totalSymbols,
                'processed_symbols' => count($results),
                'successful_symbols' => count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'READY')),
                'error_symbols' => count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'ERROR')),
                'skipped_symbols' => count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'SKIPPED')),
            ],
            'progress' => [
                'current' => $totalSymbols,
                'total' => $totalSymbols,
                'percentage' => 100.0,
                'symbol' => 'FINAL',
                'status' => 'COMPLETED',
            ],
        ];

        return $results;
    }

    /**
     * Traite un symbole spécifique selon la logique MTF
     */
    private function processSymbol(string $symbol, UuidInterface $runId, \DateTimeImmutable $now, ?string $currentTf = null, bool $forceTimeframeCheck = false, bool $forceRun = false): array
    {
        $this->logger->debug('[MTF] Processing symbol', ['symbol' => $symbol]);

        // Vérifier le kill switch du symbole (sauf si force-run est activé)
        if (!$forceRun && !$this->mtfSwitchRepository->canProcessSymbol($symbol)) {
            $this->logger->debug('[MTF] Symbol kill switch is OFF', ['symbol' => $symbol, 'force_run' => $forceRun]);
            $this->auditStep($runId, $symbol, 'KILL_SWITCH_OFF', 'Symbol kill switch is OFF');
            return ['status' => 'SKIPPED', 'reason' => 'Symbol kill switch OFF', 'failed_timeframe' => 'symbol'];
        }

        $state = $this->mtfStateRepository->getOrCreateForSymbol($symbol);
        $validationStates = [];

        // Si un timeframe spécifique est demandé, traiter seulement celui-ci
        if ($currentTf !== null) {
            $timeframeService = match($currentTf) {
                '4h' => $this->timeframe4hService,
                '1h' => $this->timeframe1hService,
                '15m' => $this->timeframe15mService,
                '5m' => $this->timeframe5mService,
                '1m' => $this->timeframe1mService,
                default => throw new \InvalidArgumentException("Invalid timeframe: $currentTf")
            };

            $result = $timeframeService->processTimeframe($symbol, $runId, $now, $validationStates, $forceTimeframeCheck, $forceRun);
            if (($result['status'] ?? null) !== 'VALID') {
                $this->auditStep($runId, $symbol, strtoupper($currentTf) . '_VALIDATION_FAILED', $result['reason'] ?? "$currentTf validation failed");
                return $result + ['failed_timeframe' => $currentTf];
            }

            // Mettre à jour l'état pour le timeframe spécifique
            $timeframeService->updateState($symbol, $result);

            $this->mtfStateRepository->getEntityManager()->flush();

            return [
                'status' => 'READY',
                'signal_side' => $result['signal_side'],
                'context' => ['single_timeframe' => $currentTf],
                'kline_time' => $result['kline_time'],
                'current_price' => $result['current_price'] ?? null,
                'atr' => $result['atr'] ?? null,
                'indicator_context' => $result['indicator_context'] ?? null,
                'execution_tf' => $currentTf,
            ];
        }

        // Logique MTF selon start_from_timeframe
        $cfg = $this->mtfConfig->getConfig();
        $startFrom = strtolower((string)($cfg['validation']['start_from_timeframe'] ?? '4h'));
        // Inclure uniquement les TF à partir de start_from_timeframe vers le bas (aucun TF supérieur)
        $include4h  = in_array($startFrom, ['4h'], true);
        $include1h  = in_array($startFrom, ['4h','1h'], true);
        $include15m = in_array($startFrom, ['4h','1h','15m'], true);
        $include5m  = in_array($startFrom, ['4h','1h','15m','5m'], true);
        $include1m  = in_array($startFrom, ['4h','1h','15m','5m','1m'], true);

        $result4h = null;
        if ($include4h) {
            $result4h = $this->timeframe4hService->processTimeframe($symbol, $runId, $now, $validationStates, $forceTimeframeCheck, $forceRun);
            if (($result4h['status'] ?? null) !== 'VALID') {
                $this->auditStep($runId, $symbol, '4H_VALIDATION_FAILED', $result4h['reason'] ?? '4H validation failed', [
                    'timeframe' => '4h',
                    'kline_time' => $result4h['kline_time'] ?? null,
                    'failed_conditions_long' => $result4h['failed_conditions_long'] ?? [],
                    'failed_conditions_short' => $result4h['failed_conditions_short'] ?? [],
                    'conditions_long' => $result4h['conditions_long'] ?? [],
                    'conditions_short' => $result4h['conditions_short'] ?? [],
                    'current_price' => $result4h['current_price'] ?? null,
                    'atr' => $result4h['atr'] ?? null,
                    'passed' => false,
                    'severity' => 2,
                ]);
                return $result4h + ['failed_timeframe' => '4h'];
            }
            $this->timeframe4hService->updateState($symbol, $result4h);
        }

        $result1h = null;
        if ($include1h) {
            $result1h = $this->timeframe1hService->processTimeframe($symbol, $runId, $now, $validationStates, $forceTimeframeCheck, $forceRun);
            if (($result1h['status'] ?? null) !== 'VALID') {
                $this->auditStep($runId, $symbol, '1H_VALIDATION_FAILED', $result1h['reason'] ?? '1H validation failed', [
                    'timeframe' => '1h',
                    'kline_time' => $result1h['kline_time'] ?? null,
                    'failed_conditions_long' => $result1h['failed_conditions_long'] ?? [],
                    'failed_conditions_short' => $result1h['failed_conditions_short'] ?? [],
                    'conditions_long' => $result1h['conditions_long'] ?? [],
                    'conditions_short' => $result1h['conditions_short'] ?? [],
                    'current_price' => $result1h['current_price'] ?? null,
                    'atr' => $result1h['atr'] ?? null,
                    'passed' => false,
                    'severity' => 2,
                ]);
                return $result1h + ['failed_timeframe' => '1h'];
            }
            if ($include4h) {
                // Règle: 1h doit matcher 4h si 4h inclus
                $alignmentResult = $this->timeframe1hService->checkAlignment($result1h, $result4h, '4H');
                if ($alignmentResult['status'] === 'INVALID') {
                    $this->auditStep($runId, $symbol, 'ALIGNMENT_FAILED', '1h side != 4h side', [
                        '4h' => $result4h['signal_side'] ?? 'NONE',
                        '1h' => $result1h['signal_side'] ?? 'NONE',
                        'timeframe' => '1h',
                        'passed' => false,
                        'severity' => 1,
                    ]);
                    return $alignmentResult;
                }
            }
            $this->timeframe1hService->updateState($symbol, $result1h);
        }

        // Étape 15m (seulement si incluse)
        $result15m = null;
        if ($include15m) {
            $result15m = $this->timeframe15mService->processTimeframe($symbol, $runId, $now, $validationStates, $forceTimeframeCheck, $forceRun);
            if (($result15m['status'] ?? null) !== 'VALID') {
                $this->auditStep($runId, $symbol, '15M_VALIDATION_FAILED', $result15m['reason'] ?? '15M validation failed', [
                    'timeframe' => '15m',
                    'kline_time' => $result15m['kline_time'] ?? null,
                    'failed_conditions_long' => $result15m['failed_conditions_long'] ?? [],
                    'failed_conditions_short' => $result15m['failed_conditions_short'] ?? [],
                    'conditions_long' => $result15m['conditions_long'] ?? [],
                    'conditions_short' => $result15m['conditions_short'] ?? [],
                    'current_price' => $result15m['current_price'] ?? null,
                    'atr' => $result15m['atr'] ?? null,
                    'passed' => false,
                    'severity' => 2,
                ]);
                return $result15m + ['failed_timeframe' => '15m'];
            }
            // Règle: 15m doit matcher 1h si 1h est inclus
            if ($include1h && is_array($result1h)) {
                $alignmentResult = $this->timeframe15mService->checkAlignment($result15m, $result1h, '1H');
                if ($alignmentResult['status'] === 'INVALID') {
                    $this->auditStep($runId, $symbol, 'ALIGNMENT_FAILED', '15m side != 1h side', [
                        '1h' => $result1h['signal_side'] ?? 'NONE',
                        '15m' => $result15m['signal_side'] ?? 'NONE',
                        'timeframe' => '15m',
                        'passed' => false,
                        'severity' => 1,
                    ]);
                    return $alignmentResult;
                }
            }
            $this->timeframe15mService->updateState($symbol, $result15m);
        }

        // Étape 5m (seulement si incluse)
        $result5m = null;
        if ($include5m) {
            $result5m = $this->timeframe5mService->processTimeframe($symbol, $runId, $now, $validationStates, $forceTimeframeCheck, $forceRun);
            if (($result5m['status'] ?? null) !== 'VALID') {
                $this->auditStep($runId, $symbol, '5M_VALIDATION_FAILED', $result5m['reason'] ?? '5M validation failed', [
                    'timeframe' => '5m',
                    'kline_time' => $result5m['kline_time'] ?? null,
                    'failed_conditions_long' => $result5m['failed_conditions_long'] ?? [],
                    'failed_conditions_short' => $result5m['failed_conditions_short'] ?? [],
                    'conditions_long' => $result5m['conditions_long'] ?? [],
                    'conditions_short' => $result5m['conditions_short'] ?? [],
                    'current_price' => $result5m['current_price'] ?? null,
                    'atr' => $result5m['atr'] ?? null,
                    'passed' => false,
                    'severity' => 2,
                ]);
                return $result5m + ['failed_timeframe' => '5m'];
            }
            // Règle: 5m doit matcher 15m si 15m est inclus
            if ($include15m && is_array($result15m)) {
                $alignmentResult = $this->timeframe5mService->checkAlignment($result5m, $result15m, '15M');
                if ($alignmentResult['status'] === 'INVALID') {
                    $this->auditStep($runId, $symbol, 'ALIGNMENT_FAILED', '5m side != 15m side', [
                        '15m' => $result15m['signal_side'] ?? 'NONE',
                        '5m' => $result5m['signal_side'] ?? 'NONE',
                        'timeframe' => '5m',
                        'passed' => false,
                        'severity' => 1,
                    ]);
                    return $alignmentResult;
                }
            }
            $this->timeframe5mService->updateState($symbol, $result5m);
        }

        // Étape 1m (seulement si incluse)
        $result1m = null;
        if ($include1m) {
            $result1m = $this->timeframe1mService->processTimeframe($symbol, $runId, $now, $validationStates, $forceTimeframeCheck, $forceRun);
            if (($result1m['status'] ?? null) !== 'VALID') {
                $this->auditStep($runId, $symbol, '1M_VALIDATION_FAILED', $result1m['reason'] ?? '1M validation failed', [
                    'timeframe' => '1m',
                    'kline_time' => $result1m['kline_time'] ?? null,
                    'failed_conditions_long' => $result1m['failed_conditions_long'] ?? [],
                    'failed_conditions_short' => $result1m['failed_conditions_short'] ?? [],
                    'conditions_long' => $result1m['conditions_long'] ?? [],
                    'conditions_short' => $result1m['conditions_short'] ?? [],
                    'current_price' => $result1m['current_price'] ?? null,
                    'atr' => $result1m['atr'] ?? null,
                    'passed' => false,
                    'severity' => 2,
                ]);
                return $result1m + ['failed_timeframe' => '1m'];
            }

            // Log dédié après validation 1m (positions_flow)
            $this->positionsFlowLogger->info('[PositionsFlow] 1m VALIDATED', [
                'symbol' => $symbol,
                'signal_side' => $result1m['signal_side'] ?? 'NONE',
                'kline_time' => isset($result1m['kline_time']) && $result1m['kline_time'] instanceof \DateTimeImmutable ? $result1m['kline_time']->format('Y-m-d H:i:s') : null,
                'current_price' => $result1m['current_price'] ?? null,
                'atr' => $result1m['atr'] ?? null,
            ]);
            // Règle: 1m doit matcher 5m si 5m est inclus
            if ($include5m && is_array($result5m)) {
                $alignmentResult = $this->timeframe1mService->checkAlignment($result1m, $result5m, '5M');
                if ($alignmentResult['status'] === 'INVALID') {
                    $this->auditStep($runId, $symbol, 'ALIGNMENT_FAILED', '1m side != 5m side', [
                        '5m' => $result5m['signal_side'] ?? 'NONE',
                        '1m' => $result1m['signal_side'] ?? 'NONE',
                        'timeframe' => '1m',
                        'passed' => false,
                        'severity' => 1,
                    ]);
                    return $alignmentResult;
                }
            }
            $this->timeframe1mService->updateState($symbol, $result1m);
        }

        // Sauvegarder l'état
        $this->mtfStateRepository->getEntityManager()->flush();

        // Construire knownSignals pour contexte
        $knownSignals = [];
        foreach ($validationStates as $vs) {
            $knownSignals[$vs['tf']] = ['signal' => strtoupper((string)($vs['signal_side'] ?? 'NONE'))];
        }

        // Choix TF exécution simple (15m>5m>1m)
        $mtfByTf = [
            '15m' => is_array($result15m) ? $result15m : [],
            '5m'  => is_array($result5m) ? $result5m : [],
            '1m'  => is_array($result1m) ? $result1m : [],
        ];
        $available = [
            '15m' => is_array($result15m) ? ($result15m['signal_side'] ?? 'NONE') : 'NONE',
            '5m'  => is_array($result5m) ? ($result5m['signal_side'] ?? 'NONE') : 'NONE',
            '1m'  => is_array($result1m) ? ($result1m['signal_side'] ?? 'NONE') : 'NONE',
        ];
        // Option B: privilégier 1m (si validé et aligné), sinon 5m, sinon 15m
        // Les vérifications d'alignement 1m↔5m et 5m↔15m ont déjà été effectuées plus haut.
        $prefOrder = ['1m','5m','15m'];
        $currentTf = '1m';
        foreach ($prefOrder as $tf) {
            $side = strtoupper((string)($available[$tf] ?? 'NONE'));
            if ($side !== 'NONE') { $currentTf = $tf; break; }
        }
        $currentSignal = strtoupper((string)($available[$currentTf] ?? 'NONE'));
        $selectedTfSnapshot = $mtfByTf[$currentTf] ?? [];
        $selectedPrice = $selectedTfSnapshot['current_price'] ?? null;
        $selectedAtr = $selectedTfSnapshot['atr'] ?? null;
        $selectedContext = $selectedTfSnapshot['indicator_context'] ?? null;
        $selectedKlineTime = $selectedTfSnapshot['kline_time'] ?? (is_array($result15m) ? ($result15m['kline_time'] ?? null) : null);

        $contextSummary = $this->signalValidationService->buildContextSummary($knownSignals, $currentTf, $currentSignal);
        $this->logger->info('[MTF] Context summary', [ 'symbol' => $symbol, 'current_tf' => $currentTf ] + $contextSummary);
        $this->auditStep($runId, $symbol, 'MTF_CONTEXT', null, ['current_tf' => $currentTf, 'timeframe' => $currentTf, 'passed' => $currentSignal !== 'NONE', 'severity' => 0] + $contextSummary);

        // Déterminer le côté cohérent minimal (respecte start_from_timeframe)
        $states = [];
        if ($include4h)  { $states[] = ['tf'=>'4h','side'=> (is_array($result4h) ? ($result4h['signal_side'] ?? 'NONE') : 'NONE')]; }
        if ($include1h)  { $states[] = ['tf'=>'1h','side'=> (is_array($result1h) ? ($result1h['signal_side'] ?? 'NONE') : 'NONE')]; }
        if ($include15m) { $states[] = ['tf'=>'15m','side'=> (is_array($result15m) ? ($result15m['signal_side'] ?? 'NONE') : 'NONE')]; }
        $consistentSide = $this->getConsistentSideSimple($states);
        if ($consistentSide === 'NONE') {
            $this->auditStep($runId, $symbol, 'NO_CONSISTENT_SIDE', 'No consistent signal side across 4h/1h/15m', [ 'passed' => false, 'severity' => 1 ]);
            return ['status' => 'NO_CONSISTENT_SIDE', 'failed_timeframe' => 'multi-tf'];
        }

        // Pour rester focalisé sur la demande, on n’applique pas de filtres supplémentaires ici.
        return [
            'status' => 'READY',
            'signal_side' => $consistentSide,
            'context' => $contextSummary,
            'kline_time' => $selectedKlineTime,
            'current_price' => $selectedPrice,
            'atr' => $selectedAtr,
            'indicator_context' => $selectedContext,
            'execution_tf' => $currentTf,
        ];
    }

    /**
     * NOUVELLE MÉTHODE : Remplit les klines manquantes en masse
     */
    private function fillMissingKlinesInBulk(
        string $symbol,
        Timeframe $timeframe,
        int $requiredLimit,
        \DateTimeImmutable $now,
        UuidInterface $runId
    ): void {
        if (!$this->klineJsonIngestion) {
            $this->logger->warning('[MTF] KlineJsonIngestionService not available, skipping bulk fill');
            return;
        }

        $this->logger->info('[MTF] Filling missing klines in bulk', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'required_limit' => $requiredLimit
        ]);

        // Calculer la période à récupérer
        $intervalMinutes = $timeframe->getStepInMinutes();
        $endDatetime = (clone $now)->sub(new \DateInterval('PT' . ($requiredLimit * $intervalMinutes) . 'M'));

        // Fetch toutes les klines manquantes d'un coup
        $fetchedKlines = $this->klineProvider->getKlinesInWindow(
            $symbol,
            $timeframe,
            $endDatetime,
            $now,
            $requiredLimit * 2 // Récupérer un peu plus pour être sûr
        );

        if (empty($fetchedKlines)) {
            $this->logger->warning('[MTF] No klines fetched from BitMart', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value
            ]);
            return;
        }

        // Insertion en masse via la fonction SQL JSON
        $result = $this->klineJsonIngestion->ingestKlinesBatch($fetchedKlines, $symbol, $timeframe->value);

        $this->logger->info('[MTF] Bulk klines insertion completed', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'fetched_count' => count($fetchedKlines),
            'inserted_count' => $result->count,
            'duration_ms' => $result->durationMs
        ]);
    }

    /**
     * Ancienne méthode processTimeframe supprimée - logique déplacée vers les services de timeframe spécialisés
     */

    private function resolveInvalidReason(
        array $evaluation,
        array $conditionsLong,
        array $conditionsShort,
        array $failedLong,
        array $failedShort
    ): string {
        $provided = $evaluation['reason'] ?? null;
        if (is_string($provided)) {
            $normalized = strtoupper(trim($provided));
            if ($normalized !== '' && $normalized !== 'NO_SIGNAL') {
                return $provided;
            }
        }

        return $this->buildFailedConditionsReason($conditionsLong, $conditionsShort, $failedLong, $failedShort);
    }

    private function buildFailedConditionsReason(
        array $conditionsLong,
        array $conditionsShort,
        array $failedLong,
        array $failedShort
    ): string {
        $parts = [];

        if ($conditionsLong === []) {
            $parts[] = 'LONG_NOT_CONFIGURED';
        } elseif ($failedLong !== []) {
            $parts[] = 'LONG_FAILED(' . implode(',', $failedLong) . ')';
        }

        if ($conditionsShort === []) {
            $parts[] = 'SHORT_NOT_CONFIGURED';
        } elseif ($failedShort !== []) {
            $parts[] = 'SHORT_FAILED(' . implode(',', $failedShort) . ')';
        }

        $parts = array_values(array_filter($parts, fn($part) => $part !== ''));
        if ($parts === []) {
            return 'CONDITIONS_NOT_MET';
        }

        return implode(' | ', $parts);
    }

    private function getConsistentSideSimple(array $states): string
    {
        $sides = array_map(fn($s) => strtoupper((string)($s['side'] ?? $s['signal_side'] ?? 'NONE')), $states);
        $sides = array_filter($sides, fn($v) => $v !== 'NONE');
        if ($sides === []) return 'NONE';
        $uniq = array_unique($sides);
        return count($uniq) === 1 ? reset($uniq) : 'NONE';
    }

    /**
     * Enregistre une étape d'audit (signature et implémentation d'origine)
     */
    private function auditStep(\Ramsey\Uuid\UuidInterface $runId, string $symbol, string $step, ?string $message = null, array $data = []): void
    {
        $audit = new MtfAudit();
        $audit->setRunId($runId);
        $audit->setSymbol($symbol);
        $audit->setStep($step);
        $audit->setCause($message);
        // Enrichir les détails avec structure standard si présente dans $data
        $details = $data;
        if (!array_key_exists('passed', $details)) {
            $details['passed'] = (bool)($data['passed'] ?? false);
        }
        if (!array_key_exists('conditions_passed', $details) && isset($data['conditions_long'], $data['conditions_short'])) {
            $details['conditions_passed'] = array_keys(array_filter(array_merge($data['conditions_long'] ?? [], $data['conditions_short'] ?? []), fn($v) => is_array($v) ? (($v['passed'] ?? false) === true) : (bool)$v));
        }
        if (!array_key_exists('conditions_failed', $details) && isset($data['failed_conditions_long'], $data['failed_conditions_short'])) {
            $details['conditions_failed'] = array_values(array_merge($data['failed_conditions_long'] ?? [], $data['failed_conditions_short'] ?? []));
        }
        if (!array_key_exists('metrics', $details) && isset($data['current_price'], $data['atr'])) {
            $details['metrics'] = [
                'price' => $data['current_price'],
                'atr' => $data['atr'],
                'atr_rel' => (isset($data['current_price'], $data['atr']) && (float)$data['current_price'] > 0) ? ((float)$data['atr'] / (float)$data['current_price']) : null,
            ];
        }
        if (!array_key_exists('guard_values', $details) && isset($data['min_bars'], $data['bars_count'])) {
            $details['guard_values'] = [
                'min_bars' => $data['min_bars'],
                'bars_count' => $data['bars_count'],
            ];
        }
        $audit->setDetails($details);

        // Optionnel: timeframe & candle_close_ts si fournis
        if (isset($data['timeframe']) && is_string($data['timeframe'])) {
            try {
                $audit->setTimeframe(\App\Common\Enum\Timeframe::from($data['timeframe']));
            } catch (\Throwable) {}
        }
        if (isset($data['kline_time']) && $data['kline_time'] instanceof \DateTimeImmutable) {
            $audit->setCandleCloseTs($data['kline_time']);
        }
        if (isset($data['severity']) && is_numeric($data['severity'])) {
            $audit->setSeverity((int)$data['severity']);
        }
        $audit->setCreatedAt($this->clock->now());

        // Ajouter le run_id aux détails
        $details = $audit->getDetails();
        $details['run_id'] = $runId->toString();
        $audit->setDetails($details);

        // Dispatcher l'événement pour déléger la persistance au subscriber
        $this->eventDispatcher->dispatch(new MtfAuditEvent(
            $audit->getSymbol(),
            $audit->getStep(),
            $audit->getCause(),
            $audit->getDetails(),
            $audit->getSeverity()
        ), MtfAuditEvent::NAME);
    }

    /**
     * Persiste les résultats MTF (cache de validation)
     */
    private function persistMtfResults(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $klineTime,
        string $signalSide,
        array $evaluation,
        array $collector
    ): void {
        try {
            // Persister le cache de validation
            if ($this->validationCache !== null) {
                $status = match ($signalSide) {
                    'LONG', 'SHORT' => 'VALID',
                    'NONE' => 'INVALID',
                    default => 'PENDING'
                };

                $details = [
                    'signal_side' => $signalSide,
                    'conditions_long' => $evaluation['conditions_long'] ?? [],
                    'conditions_short' => $evaluation['conditions_short'] ?? [],
                    'indicator_context' => $evaluation['indicator_context'] ?? [],
                    'mtf_collector' => $collector,
                    'persisted_by' => 'mtf_service'
                ];

                // Calculer l'expiration selon le timeframe (moins 1 seconde pour éviter les problèmes de timing)
                $now = $this->timeService->getCurrentAlignedUtc();
                $expirationTime = $this->timeService->getValidationCacheTtl($now, $timeframe);
                $expirationTime = $expirationTime->modify('-1 second');
                $expirationMinutes = (int) ceil(($expirationTime->getTimestamp() - $now->getTimestamp()) / 60);

                $this->validationCache->cacheMtfValidation(
                    $symbol,
                    $timeframe,
                    $klineTime,
                    $status,
                    $details,
                    $expirationMinutes
                );

                $this->logger->info('MTF validation cached', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'status' => $status,
                    'kline_time' => $klineTime->format('Y-m-d H:i:s'),
                    'expiration_minutes' => $expirationMinutes,
                    'expiration_time' => $expirationTime->format('Y-m-d H:i:s')
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to persist MTF results', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Expose le traitement d'un symbole pour délégation externe.
     * @return \Generator<int, array{symbol: string, result: array, progress: array}, array>
     */
    public function runForSymbol(\Ramsey\Uuid\UuidInterface $runId, string $symbol, \DateTimeImmutable $now, ?string $currentTf = null, bool $forceTimeframeCheck = false, bool $forceRun = false): \Generator
    {
        $result = $this->processSymbol($symbol, $runId, $now, $currentTf, $forceTimeframeCheck, $forceRun);

        // Yield progress information for single symbol
        $progress = [
            'current' => 1,
            'total' => 1,
            'percentage' => 100.0,
            'symbol' => $symbol,
            'status' => $result['status'] ?? 'unknown',
        ];

        yield [
            'symbol' => $symbol,
            'result' => $result,
            'progress' => $progress,
        ];

        return $result;
    }
}
