<?php

declare(strict_types=1);

namespace App\Domain\Mtf\Service;

use App\Config\MtfConfigProviderInterface;
use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Dto\ValidationStateDto;
use App\Domain\Common\Enum\SignalSide;
use App\Domain\Common\Enum\Timeframe;
use App\Entity\MtfAudit;
use App\Repository\KlineRepository;
use App\Repository\MtfAuditRepository;
use App\Repository\MtfStateRepository;
use App\Repository\MtfSwitchRepository;
use App\Repository\ContractRepository;
use Brick\Math\BigDecimal;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use App\Signal\SignalValidationService;
use App\Entity\Contract;
use App\Domain\Ports\Out\KlineProviderPort;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use App\Infrastructure\Persistence\SignalPersistenceService;
use App\Infrastructure\Persistence\KlineJsonIngestionService;
use App\Infrastructure\Cache\DbValidationCache;
use App\Event\MtfAuditEvent;
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
        private readonly SignalValidationService $signalValidationService,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $positionsFlowLogger,
        private readonly MtfConfigProviderInterface $mtfConfig,
        private readonly KlineProviderPort $klineProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?SignalPersistenceService $signalPersistenceService = null,
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
            $timeframe = match($currentTf) {
                '4h' => Timeframe::TF_4H,
                '1h' => Timeframe::TF_1H,
                '15m' => Timeframe::TF_15M,
                '5m' => Timeframe::TF_5M,
                '1m' => Timeframe::TF_1M,
                default => throw new \InvalidArgumentException("Invalid timeframe: $currentTf")
            };

            $result = $this->processTimeframe($symbol, $timeframe, $runId, $now, $validationStates, $forceTimeframeCheck, $forceRun);
            if (($result['status'] ?? null) !== 'VALID') {
                $this->auditStep($runId, $symbol, strtoupper($currentTf) . '_VALIDATION_FAILED', $result['reason'] ?? "$currentTf validation failed");
                return $result + ['failed_timeframe' => $currentTf];
            }

            // Mettre à jour l'état pour le timeframe spécifique
            match($currentTf) {
                '4h' => $state->setK4hTime($result['kline_time'])->set4hSide($result['signal_side']),
                '1h' => $state->setK1hTime($result['kline_time'])->set1hSide($result['signal_side']),
                '15m' => $state->setK15mTime($result['kline_time'])->set15mSide($result['signal_side']),
                '5m' => $state->setK5mTime($result['kline_time'])->set5mSide($result['signal_side']),
                '1m' => $state->setK1mTime($result['kline_time'])->set1mSide($result['signal_side']),
            };

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
            $result4h = $this->processTimeframe($symbol, Timeframe::TF_4H, $runId, $now, $validationStates, $forceTimeframeCheck, $forceRun);
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
            $state->setK4hTime($result4h['kline_time']);
            $state->set4hSide($result4h['signal_side']);
        }

        $result1h = null;
        if ($include1h) {
            $result1h = $this->processTimeframe($symbol, Timeframe::TF_1H, $runId, $now, $validationStates, $forceTimeframeCheck, $forceRun);
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
                if (strtoupper((string)($result1h['signal_side'] ?? 'NONE')) !== strtoupper((string)($result4h['signal_side'] ?? 'NONE'))) {
            $this->auditStep($runId, $symbol, 'ALIGNMENT_FAILED', '1h side != 4h side', [
                        '4h' => $result4h['signal_side'] ?? 'NONE',
                        '1h' => $result1h['signal_side'] ?? 'NONE',
                'timeframe' => '1h',
                'passed' => false,
                'severity' => 1,
                    ]);
                    $extra = [];
                    foreach (['conditions_long','conditions_short','failed_conditions_long','failed_conditions_short'] as $k) {
                        if (isset($result1h[$k])) { $extra[$k] = $result1h[$k]; }
                    }
                    return ['status' => 'INVALID', 'reason' => 'ALIGNMENT_1H_NE_4H', 'failed_timeframe' => '1h'] + $extra;
                }
            }
            $state->setK1hTime($result1h['kline_time']);
            $state->set1hSide($result1h['signal_side']);
        }

        // Étape 15m (seulement si incluse)
        $result15m = null;
        if ($include15m) {
            $result15m = $this->processTimeframe($symbol, Timeframe::TF_15M, $runId, $now, $validationStates, $forceTimeframeCheck, $forceRun);
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
                if (strtoupper((string)($result15m['signal_side'] ?? 'NONE')) !== strtoupper((string)($result1h['signal_side'] ?? 'NONE'))) {
            $this->auditStep($runId, $symbol, 'ALIGNMENT_FAILED', '15m side != 1h side', [
                        '1h' => $result1h['signal_side'] ?? 'NONE',
                        '15m' => $result15m['signal_side'] ?? 'NONE',
                'timeframe' => '15m',
                'passed' => false,
                'severity' => 1,
                    ]);
                    $extra = [];
                    foreach (['conditions_long','conditions_short','failed_conditions_long','failed_conditions_short'] as $k) {
                        if (isset($result15m[$k])) { $extra[$k] = $result15m[$k]; }
                    }
                    return ['status' => 'INVALID', 'reason' => 'ALIGNMENT_15M_NE_1H', 'failed_timeframe' => '15m'] + $extra;
                }
            }
            $state->setK15mTime($result15m['kline_time']);
            $state->set15mSide($result15m['signal_side']);
        }

        // Étape 5m (seulement si incluse)
        $result5m = null;
        if ($include5m) {
            $result5m = $this->processTimeframe($symbol, Timeframe::TF_5M, $runId, $now, $validationStates, $forceTimeframeCheck, $forceRun);
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
                if (strtoupper((string)($result5m['signal_side'] ?? 'NONE')) !== strtoupper((string)($result15m['signal_side'] ?? 'NONE'))) {
            $this->auditStep($runId, $symbol, 'ALIGNMENT_FAILED', '5m side != 15m side', [
                        '15m' => $result15m['signal_side'] ?? 'NONE',
                        '5m' => $result5m['signal_side'] ?? 'NONE',
                'timeframe' => '5m',
                'passed' => false,
                'severity' => 1,
                    ]);
                    $extra = [];
                    foreach (['conditions_long','conditions_short','failed_conditions_long','failed_conditions_short'] as $k) {
                        if (isset($result5m[$k])) { $extra[$k] = $result5m[$k]; }
                    }
                    return ['status' => 'INVALID', 'reason' => 'ALIGNMENT_5M_NE_15M', 'failed_timeframe' => '5m'] + $extra;
                }
            }
            $state->set5mSide($result5m['signal_side'] ?? null);
        }

        // Étape 1m (seulement si incluse)
        $result1m = null;
        if ($include1m) {
            $result1m = $this->processTimeframe($symbol, Timeframe::TF_1M, $runId, $now, $validationStates, $forceTimeframeCheck, $forceRun);
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
                if (strtoupper((string)($result1m['signal_side'] ?? 'NONE')) !== strtoupper((string)($result5m['signal_side'] ?? 'NONE'))) {
                $this->auditStep($runId, $symbol, 'ALIGNMENT_FAILED', '1m side != 5m side', [
                        '5m' => $result5m['signal_side'] ?? 'NONE',
                        '1m' => $result1m['signal_side'] ?? 'NONE',
                        'timeframe' => '1m',
                        'passed' => false,
                        'severity' => 1,
                            ]);
                    $extra = [];
                    foreach (['conditions_long','conditions_short','failed_conditions_long','failed_conditions_short'] as $k) {
                        if (isset($result1m[$k])) { $extra[$k] = $result1m[$k]; }
                    }
                    return ['status' => 'INVALID', 'reason' => 'ALIGNMENT_1M_NE_5M', 'failed_timeframe' => '1m'] + $extra;
                }
            }
            $state->set1mSide($result1m['signal_side'] ?? null);
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
        $startDate = (clone $now)->sub(new \DateInterval('PT' . ($requiredLimit * $intervalMinutes) . 'M'));

        // Fetch toutes les klines manquantes d'un coup
        $fetchedKlines = $this->klineProvider->fetchKlinesInWindow(
            $symbol,
            $timeframe,
            $startDate,
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
        $result = $this->klineJsonIngestion->ingestKlinesBatch($fetchedKlines);

        $this->logger->info('[MTF] Bulk klines insertion completed', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'fetched_count' => count($fetchedKlines),
            'inserted_count' => $result->count,
            'duration_ms' => $result->durationMs
        ]);
    }

    /**
     * Valide un timeframe via SignalValidationService. Retourne INVALID si signal = NONE.
     * Ajoute l'état minimal dans $collector pour construire le contexte MTF.
     *
     * NOUVELLE LOGIQUE : Insertion en masse au lieu de backfill complexe
     */
    private function processTimeframe(string $symbol, Timeframe $timeframe, UuidInterface $runId, \DateTimeImmutable $now, array &$collector, bool $forceTimeframeCheck = false, bool $forceRun = false): array
    {
        try {
        // Vérification des min_bars AVANT la vérification TOO_RECENT pour désactiver les symboles si nécessaire
        $limit = 270; // fallback
        try {
            $cfg = $this->mtfConfig->getConfig();
            $limit = (int)($cfg['timeframes'][$timeframe->value]['guards']['min_bars'] ?? 270);
        } catch (\Throwable $ex) {
            $this->logger->error("[MTF] Error loading config for {$timeframe->value}, using default limit", ['error' => $ex->getMessage()]);
        }

        $klines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframe, $limit);

        // 🔥 NOUVELLE LOGIQUE : Si pas assez de klines → INSÉRER EN MASSE
        if (count($klines) < $limit) {
            $this->logger->info('[MTF] Insufficient klines, filling in bulk', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'current_count' => count($klines),
                'required_count' => $limit
            ]);

            // Remplir les klines manquantes en masse
            $this->fillMissingKlinesInBulk($symbol, $timeframe, $limit, $now, $runId);

            // Recharger les klines après insertion
            $klines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframe, $limit);

            // Si toujours pas assez après insertion → désactiver temporairement
            if (count($klines) < $limit) {
                $missingBars = $limit - count($klines);
                $duration = ($missingBars * $timeframe->getStepInMinutes() + $timeframe->getStepInMinutes()) . ' minutes';
                $this->mtfSwitchRepository->turnOffSymbolForDuration($symbol, $duration);

                $this->auditStep($runId, $symbol, "{$timeframe->value}_INSUFFICIENT_DATA_AFTER_FILL", "Still insufficient bars after bulk fill", [
                    'timeframe' => $timeframe->value,
                    'bars_count' => count($klines),
                    'min_bars' => $limit,
                    'missing_bars' => $missingBars,
                    'duration_disabled' => $duration
                ]);
                return ['status' => 'SKIPPED', 'reason' => 'INSUFFICIENT_DATA_AFTER_FILL', 'failed_timeframe' => $timeframe->value];
            }
        }

        // Ajout de la vérification de la fraîcheur de la dernière kline (sauf si force-run ou force-timeframe-check)
        if (!$forceTimeframeCheck && !$forceRun) {
            $lastKline = $this->klineRepository->findLastBySymbolAndTimeframe($symbol, $timeframe);
            if ($lastKline) {
                $interval = new \DateInterval('PT' . $timeframe->getStepInMinutes() . 'M');
                $threshold = $now->sub($interval);
                if ($lastKline->getOpenTime() > $threshold) {
                    $this->auditStep($runId, $symbol, "{$timeframe->value}_SKIPPED_TOO_RECENT", "Last kline is too recent", [
                        'timeframe' => $timeframe->value,
                        'last_kline_time' => $lastKline->getOpenTime()->format('Y-m-d H:i:s'),
                        'threshold' => $threshold->format('Y-m-d H:i:s')
                    ]);
                    return ['status' => 'SKIPPED', 'reason' => 'TOO_RECENT'];
                }
            }
        }

        // Kill switch TF (sauf si force-run est activé)
        if (!$forceRun && !$this->mtfSwitchRepository->canProcessSymbolTimeframe($symbol, $timeframe->value)) {
            $this->auditStep($runId, $symbol, "{$timeframe->value}_KILL_SWITCH_OFF", "{$timeframe->value} kill switch is OFF", ['timeframe' => $timeframe->value, 'force_run' => $forceRun]);
            return ['status' => 'SKIPPED', 'reason' => "{$timeframe->value} kill switch OFF"];
        }

        // Fenêtre de grâce (sauf si force-run est activé)
        if (!$forceRun && $this->timeService->isInGraceWindow($now, $timeframe)) {
            $this->auditStep($runId, $symbol, "{$timeframe->value}_GRACE_WINDOW", "In grace window for {$timeframe->value}", ['timeframe' => $timeframe->value, 'force_run' => $forceRun]);
            return ['status' => 'GRACE_WINDOW', 'reason' => "In grace window for {$timeframe->value}"];
        }

        // ✅ SUITE NORMALE : Vérifications de fraîcheur, kill switches, etc.
        // Reverser en ordre chronologique ascendant
        usort($klines, fn($a, $b) => $a->getOpenTime() <=> $b->getOpenTime());

        // 🔄 ANCIENNE LOGIQUE COMMENTÉE POUR ROLLBACK POSSIBLE
        /*
        // --- Détection et comblement des trous via getMissingKlineChunks ---

        // Calculer la plage temporelle à analyser
        $intervalMinutes = $timeframe->getStepInMinutes();
        $startDate = (clone $now)->sub(new \DateInterval('PT' . ($limit * $intervalMinutes) . 'M'));
        $endDate = $now;

        // Utiliser la fonction PostgreSQL pour détecter les trous
        $missingChunks = $this->klineRepository->getMissingKlineChunks(
            $symbol,
            $timeframe->value,
            $startDate,
            $endDate,
            500 // max_per_request
        );

        if (!empty($missingChunks)) {
            $this->auditStep($runId, $symbol, "{$timeframe->value}_GAPS_DETECTED", "Gaps detected via PostgreSQL, attempting to fill", [
                'timeframe' => $timeframe->value,
                'chunks_count' => count($missingChunks),
                'chunks' => array_map(fn($c) => [
                    'from' => date('Y-m-d H:i:s', $c['from']),
                    'to' => date('Y-m-d H:i:s', $c['to']),
                    'step' => $c['step']
                ], $missingChunks)
            ]);

            $allNewKlines = [];
            foreach ($missingChunks as $chunk) {
                try {
                    $chunkStart = \DateTimeImmutable::createFromFormat('U', (string)$chunk['from']);
                    $chunkEnd = \DateTimeImmutable::createFromFormat('U', (string)$chunk['to']);

                    if ($chunkStart && $chunkEnd) {
                        $fetchedKlines = $this->klineProvider->fetchKlinesInWindow(
                            $symbol,
                            $timeframe,
                            $chunkStart,
                            $chunkEnd
                        );

                        if (!empty($fetchedKlines)) {
                            $allNewKlines = array_merge($allNewKlines, $fetchedKlines);
                        }
                    }
                } catch (\Throwable $e) {
                    $this->auditStep($runId, $symbol, "{$timeframe->value}_CHUNK_FETCH_ERROR", "Error fetching chunk", [
                        'chunk' => $chunk,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            if (!empty($allNewKlines)) {
                // Filtrer les doublons avant de sauvegarder
                $firstChunkStart = \DateTimeImmutable::createFromFormat('U', (string)$missingChunks[0]['from']);
                $lastChunkEnd = \DateTimeImmutable::createFromFormat('U', (string)end($missingChunks)['to']);

                if ($firstChunkStart && $lastChunkEnd) {
                    $existingKlinesInRange = $this->klineRepository->findBySymbolTimeframeAndDateRange(
                        $symbol,
                        $timeframe,
                        $firstChunkStart,
                        $lastChunkEnd
                    );
                    $existingOpenTimes = array_map(fn($k) => $k->getOpenTime()->getTimestamp(), $existingKlinesInRange);

                    $uniqueNewKlines = array_filter($allNewKlines, fn($bk) => !in_array($bk->openTime->getTimestamp(), $existingOpenTimes));

                    if (!empty($uniqueNewKlines)) {
                        $this->klineRepository->saveKlines($uniqueNewKlines);
                        $this->auditStep($runId, $symbol, "{$timeframe->value}_GAPS_FILLED", "Gaps filled successfully", [
                            'timeframe' => $timeframe->value,
                            'new_klines_count' => count($uniqueNewKlines)
                        ]);

                        // Recharger les klines pour garantir la fraîcheur et la complétude des données
                        $klines = $this->klineRepository->findBySymbolAndTimeframe($symbol, $timeframe, $limit);
                        usort($klines, fn($a, $b) => $a->getOpenTime() <=> $b->getOpenTime());
                    }
                }
            }
        }
        */

        // --- Fin de la logique de comblement (ANCIENNE LOGIQUE COMMENTÉE) ---

        // Construire Contract avec symbole (requis par AbstractSignal->buildIndicatorContext)
        $contract = (new Contract())->setSymbol($symbol);

        // Construire knownSignals minimal depuis le collector courant
        $known = [];
        foreach ($collector as $c) { $known[$c['tf']] = ['signal' => strtoupper((string)($c['signal_side'] ?? 'NONE'))]; }

        // S'assurer que $klines contient des entités Kline et non des KlineDto
        $klineEntities = [];
        $klineDtoCount = 0;
        $klineEntityCount = 0;
        $unknownCount = 0;

        foreach ($klines as $kline) {
            if ($kline instanceof \App\Domain\Common\Dto\KlineDto) {
                $klineDtoCount++;
                $this->logger->warning('[MTF] Found KlineDto in klines array, this should not happen', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'kline_dto_count' => $klineDtoCount
                ]);
                continue; // Skip les KlineDto
            } elseif ($kline instanceof \App\Entity\Kline) {
                $klineEntityCount++;
                $klineEntities[] = $kline;
            } else {
                $unknownCount++;
                $this->logger->error('[MTF] Found unknown object type in klines array', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'object_type' => get_class($kline),
                    'unknown_count' => $unknownCount
                ]);
            }
        }

        $this->logger->info('[MTF] Klines filtering results', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'total_klines' => count($klines),
            'kline_entities' => $klineEntityCount,
            'kline_dtos' => $klineDtoCount,
            'unknown_objects' => $unknownCount
        ]);

        // Valider via SignalValidationService
        try {
            $res = $this->signalValidationService->validate(strtolower($timeframe->value), $klineEntities, $known, $contract);
        } catch (\Throwable $e) {
            $this->logger->error('[MTF] Error in SignalValidationService::validate', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage(),
                'kline_entities_count' => count($klineEntities),
                'kline_dto_count' => $klineDtoCount,
                'unknown_count' => $unknownCount
            ]);

            // Retourner un résultat d'erreur au lieu de faire planter
            return [
                'status' => 'ERROR',
                'reason' => 'VALIDATION_ERROR',
                'error' => $e->getMessage(),
                'failed_timeframe' => $timeframe->value
            ];
        }
        $tfKey = strtolower($timeframe->value);
        $eval = $res['signals'][$tfKey] ?? [];
        $sig = strtoupper((string)($eval['signal'] ?? 'NONE'));
        $indicatorContext = isset($eval['indicator_context']) && is_array($eval['indicator_context'])
            ? $eval['indicator_context']
            : null;
        $currentPrice = is_array($indicatorContext) ? ($indicatorContext['close'] ?? null) : null;
        $atrValue = is_array($indicatorContext) ? ($indicatorContext['atr'] ?? null) : null;

        $lastClosedTime = $this->timeService->getLastClosedKlineTime($now, $timeframe);
        $collector[] = [
            'tf' => $tfKey,
            'signal_side' => $sig,
            'kline_time' => $lastClosedTime,
            'current_price' => $currentPrice,
            'atr' => $atrValue,
        ];

        // Persister le signal et le cache de validation si les services sont disponibles
        $this->persistMtfResults($symbol, $timeframe, $lastClosedTime, $sig, $eval, $collector);

        // Extraire les conditions et calculer celles échouées
        $conditionsLong = (array)($eval['conditions_long'] ?? []);
        $conditionsShort = (array)($eval['conditions_short'] ?? []);
        $failedLong = [];
        foreach ($conditionsLong as $name => $data) {
            if (!(($data['passed'] ?? false) === true)) { $failedLong[] = (string)$name; }
        }
        $failedShort = [];
        foreach ($conditionsShort as $name => $data) {
            if (!(($data['passed'] ?? false) === true)) { $failedShort[] = (string)$name; }
        }

        $reason = $this->resolveInvalidReason(
            $eval,
            $conditionsLong,
            $conditionsShort,
            $failedLong,
            $failedShort
        );

        if ($sig === 'NONE') {
            return [
                'status' => 'INVALID',
                'reason' => $reason,
                'kline_time' => $lastClosedTime,
                'signal_side' => 'NONE',
                'conditions_long' => $conditionsLong,
                'conditions_short' => $conditionsShort,
                'failed_conditions_long' => $failedLong,
                'failed_conditions_short' => $failedShort,
                'current_price' => $currentPrice,
                'atr' => $atrValue,
                'indicator_context' => $indicatorContext,
            ];
        }

        $this->auditStep($runId, $symbol, strtoupper($tfKey).'_VALIDATED', "$tfKey validated via SignalValidationService", [ 'signal' => $sig ]);
        return [
            'status' => 'VALID',
            'kline_time' => $lastClosedTime,
            'signal_side' => $sig,
            'conditions_long' => $conditionsLong,
            'conditions_short' => $conditionsShort,
            'failed_conditions_long' => $failedLong,
            'failed_conditions_short' => $failedShort,
            'current_price' => $currentPrice,
            'atr' => $atrValue,
            'indicator_context' => $indicatorContext,
        ];

        } catch (\Throwable $e) {
            $this->logger->error('[MTF] Error in processTimeframe', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'ERROR',
                'reason' => 'PROCESS_TIMEFRAME_ERROR',
                'error' => $e->getMessage(),
                'failed_timeframe' => $timeframe->value
            ];
        }
    }

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
                $audit->setTimeframe(\App\Domain\Common\Enum\Timeframe::from($data['timeframe']));
            } catch (\Throwable) {}
        }
        if (isset($data['kline_time']) && $data['kline_time'] instanceof \DateTimeImmutable) {
            $audit->setCandleCloseTs($data['kline_time']);
        }
        if (isset($data['severity']) && is_numeric($data['severity'])) {
            $audit->setSeverity((int)$data['severity']);
        }
        $audit->setCreatedAt($this->clock->now());
        // Dispatcher l'événement pour déléger la persistance au subscriber
        $this->eventDispatcher->dispatch(new MtfAuditEvent($audit), MtfAuditEvent::NAME);
    }

    /**
     * Persiste les résultats MTF (signaux et cache de validation)
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
            // Persister le signal si ce n'est pas NONE
            if ($signalSide !== 'NONE' && $this->signalPersistenceService !== null) {
                $signalDto = new \App\Domain\Common\Dto\SignalDto(
                    symbol: $symbol,
                    timeframe: $timeframe,
                    klineTime: $klineTime,
                    side: \App\Domain\Common\Enum\SignalSide::from($signalSide),
                    score: $evaluation['score'] ?? null,
                    trigger: $evaluation['trigger'] ?? null,
                    meta: array_merge($evaluation['meta'] ?? [], [
                        'mtf_context' => $collector,
                        'evaluation' => $evaluation,
                        'persisted_by' => 'mtf_service'
                    ])
                );

                $this->signalPersistenceService->persistMtfSignal(
                    $signalDto,
                    $collector,
                    $evaluation
                );

                $this->logger->info('MTF signal persisted', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'side' => $signalSide,
                    'kline_time' => $klineTime->format('Y-m-d H:i:s')
                ]);
            }

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
