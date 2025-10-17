<?php

declare(strict_types=1);

namespace App\Service\Pipeline;

use App\Bitmart\Http\BitmartHttpClientPublic;
use App\Entity\Contract;
use App\Repository\BlacklistedContractRepository;
use App\Repository\KlineRepository;
use App\Repository\UserConfigRepository;
use App\Service\Bitmart\BitmartRefreshService;
use App\Service\Exception\Trade\Position\LeverageLowException;
use App\Service\Persister\KlinePersister;
use App\Service\Pipeline\PipelineMeta;
use App\Service\Pipeline\SlotService;
use App\Service\Signals\HighConviction\HighConvictionMetricsBuilder;
use App\Service\Signals\Timeframe\SignalService;
use App\Service\Strategy\HighConvictionTraceWriter;
use App\Service\Strategy\HighConvictionValidation;
use App\Service\Trading\BitmartAccountGateway;
use App\Service\Trading\PositionOpener;
use App\Service\Trading\ScalpModeTriggerService;
use App\Util\TimeframeHelper;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service d’exécution “callback” (extrait du contrôleur HTTP).
 * Utilisation :
 *   $runner->run(symbol: 'BTCUSDT', timeframe: '5m', limit: 270, meta: ['source' => 'cli']);
 */
final class KlinesCallbackRunner
{
    private const LIMIT_FALLBACK = 270;

    public function __construct(
        private readonly EntityManagerInterface       $em,
        private readonly MtfSignalStore               $signalStore,
        private readonly MtfDecisionService           $decisionService,
        private readonly MtfStateService              $mtfStateService,
        private readonly KlineRepository              $klineRepository,
        private readonly PositionOpener               $positionOpener,
        private readonly ScalpModeTriggerService      $scalpModeTrigger,
        private readonly HighConvictionMetricsBuilder $hcMetricsBuilder,
        private readonly UserConfigRepository         $userConfigRepository,
        private readonly Connection                   $connection,
        private readonly HighConvictionValidation     $highConviction,
        private readonly HighConvictionTraceWriter    $hcTraceWriter,
        private readonly BitmartAccountGateway        $bitmartAccount,
        private readonly BitmartHttpClientPublic      $bitmart,
        private readonly KlinePersister               $persister,
        private readonly CallbackEvalService          $callbackEval,
        private readonly SlotService                  $slotService,
        private readonly LoggerInterface              $logger,            // canal par défaut
        private readonly LoggerInterface              $validationLogger,  // ex: monolog.logger.validation
        private readonly SignalService                $signalService,
        private readonly BitmartRefreshService        $refreshService,
        private readonly BlacklistedContractRepository $blacklistedContractRepository,
    ) {}

    /**
     * Exécute le pipeline complet pour un symbole/TF.
     *
     * @param string $symbol    ex: BTCUSDT
     * @param string $timeframe ex: 4h|1h|15m|5m|1m (insensible à la casse)
     * @param int    $limit     nombre max de bougies à récupérer (min 260 recommandé)
     * @param array  $meta      {batch_id?, request_id?, root_tf?, parent_tf?, source?, pipeline?}
     *
     * @return array Résumé exécution (status, decision, signals…)
     */
    public function run(string $symbol, string $timeframe, int $limit = self::LIMIT_FALLBACK, array $meta = []): array
    {
        $symbol    = strtoupper(trim($symbol));
        $timeframe = strtolower(trim($timeframe));
        $limit     = max(1, $limit);

        /** @var Contract|null $contract */
        $contract = $this->em->getRepository(Contract::class)->findOneBy(['symbol' => $symbol]);
        if (!$contract) {
            return ['status' => 'error', 'message' => "Contract not found: $symbol"];
        }

        // Normalise step (minutes) pour BitMart REST
        $stepMinutes = TimeframeHelper::parseTimeframeToMinutes($timeframe);

        // === Fetch des dernières bougies clôturées ===
        $klinesDto = $this->bitmart->getFuturesKlines(
            symbol: $symbol,
            step:   $stepMinutes,
            fromTs: null,
            toTs:   null,
            limit:  $limit
        );

        // === Persist (upsert) ===
        $affected = $this->persister->upsertMany($contract, $stepMinutes, $klinesDto);

        // === Lookback suffisant pour l'évaluation ===
        $lookback = max(260, $limit);
        $klines   = $this->klineRepository->findRecentBySymbolAndTimeframe($symbol, $timeframe, $lookback);
        $klines   = array_values($klines);

        // === Contrôle “cutoff” / dernière bougie ===
        $cutoff = TimeframeHelper::getAlignedOpenByMinutes($stepMinutes);
        $lastPersisted = null;
        if ($klines) {
            $last = $klines[\count($klines) - 1];
            $lastPersisted = method_exists($last, 'getTimestamp') ? $last->getTimestamp() : null;
        }
        $this->logger->info('Klines cutoff control', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'step_minutes' => $stepMinutes,
            'cutoff_ts' => $cutoff->getTimestamp(),
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            'last_persisted_ts' => $lastPersisted?->getTimestamp(),
            'last_persisted' => $lastPersisted?->format('Y-m-d H:i:s'),
            'has_non_closed' => $lastPersisted ? ($lastPersisted->getTimestamp() >= $cutoff->getTimestamp()) : null,
            'persisted_count' => \count($klines),
            'meta' => $this->compactMeta($meta),
        ]);

        // === Stale → rafraîchir et sortir 202 ===
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $staleThreshold = $now->modify('-'.$stepMinutes.' minutes');
        $isBlacklisted = $this->blacklistedContractRepository->isBlacklisted($symbol);
        if ($lastPersisted && $lastPersisted < $staleThreshold && !$isBlacklisted) {
            $this->logger->warning('Last kline is stale, triggering refresh and skipping evaluation', [
                'symbol' => $symbol, 'timeframe' => $timeframe,
                'last_persisted' => $lastPersisted->format('Y-m-d H:i:s'),
                'threshold' => $staleThreshold->format('Y-m-d H:i:s'),
                'meta' => $this->compactMeta($meta),
            ]);
            $this->refreshService->refreshSingle($symbol, $timeframe, $limit);
            return ['status' => 'refreshed', 'reason' => 'stale_last_kline', 'symbol' => $symbol, 'timeframe' => $timeframe];
        }

        // === Parent frais requis ? ===
        $slot = $this->slotService->currentSlot($timeframe, $now);
        if (!$this->callbackEval->ensureParentFresh($symbol, $timeframe, $slot)) {
            return ['status' => 'pending_parent', 'symbol' => $symbol, 'timeframe' => $timeframe];
        }

        // === Évaluation signaux ===
        $previousSnapshot = $this->signalStore->fetchLatestSignals($symbol);
        $knownSignals     = $this->buildKnownSignals($previousSnapshot);

        $result = $this->signalService->evaluate($timeframe, $klines, $knownSignals);

        $signalsPayload = $result['signals'] ?? [];
        $signalsPayload[$timeframe] = $signalsPayload[$timeframe] ?? ['signal' => 'NONE'];
        $signalsPayload['final']  = $result['final']  ?? ['signal' => 'NONE'];
        $signalsPayload['status'] = $result['status'] ?? 'UNKNOWN';

        // === Persistance des “facts”/meta ===
        $facts = $this->buildFactsFromSignals(
            $signalsPayload,
            $timeframe,
            $knownSignals,
            [
                'envelope_meta' => $this->compactMeta($meta),
            ]
        );
        $this->callbackEval->persistEvaluation($symbol, $timeframe, $slot, $facts);

        // === Décision ===
        $latestSnapshot  = $this->signalStore->fetchLatestSignals($symbol);
        $eligibility     = $this->signalStore->fetchEligibility($symbol);
        $decisionSignals = $this->buildDecisionSignals($latestSnapshot);
        $decision        = $this->decisionService->decide($timeframe, $decisionSignals);
        $isValid         = $decision['is_valid'];
        $canEnter        = $decision['can_enter'];
        $contextSignals  = $this->buildContextSignals($latestSnapshot, $signalsPayload);

        $this->validationLogger->info(' --- END Evaluating signal '.$timeframe.' --- ');
        $this->validationLogger->info('signals.payload', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'signal' => $signalsPayload[$timeframe]['signal'] ?? 'NONE',
            'signals' => array_map(
                static fn($signal, $key) => "$key => " . ($signal['signal'] ?? 'NONE'),
                $contextSignals,
                array_keys($contextSignals)
            ),
            'final_signal' => $signalsPayload['final']['signal'] ?? 'NONE',
            'status' => $signalsPayload['status'] ?? null,
            'meta' => $this->compactMeta($meta),
        ]);

        // === Ouverture potentielle ===
        $finalSide = strtoupper($signalsPayload['final']['signal'] ?? $signalsPayload[$timeframe]['signal'] ?? 'NONE');
        if (!($timeframe === '4h' && $finalSide === 'NONE')) {
            $contextTrail = [];
            $contextTrail[] = ['step' => 'decision_applied', 'timeframe' => $timeframe, 'is_valid' => $isValid, 'can_enter' => $canEnter];

            $tfOpenable = ['5m','1m'];
            $eligibilityLocked = $this->isExecutionLocked($eligibility, $this->executionTimeframes($timeframe));

            $metaForPipeline = $meta['pipeline'] ?? null;
            if ($metaForPipeline === PipelineMeta::DONT_INC_DEC_DEL) {
                $contextTrail[] = ['step' => 'meta_skip'];
                $this->validationLogger->info('Skipping window due to meta flag', [
                    'symbol' => $symbol, 'timeframe' => $timeframe, 'trail' => $contextTrail, 'meta' => $this->compactMeta($meta),
                ]);
            } elseif ($canEnter && \in_array($timeframe, $tfOpenable, true) && !$eligibilityLocked) {
                $contextTrail[] = ['step' => 'window_eligible'];
                $this->validationLogger->info('Window eligible for order opening', [
                    'symbol' => $symbol, 'timeframe' => $timeframe, 'trail' => $contextTrail, 'meta' => $this->compactMeta($meta),
                ]);

                if (\in_array($finalSide, ['LONG','SHORT'], true)) {
                    $contextTrail[] = ['step' => 'final_side', 'side' => $finalSide];
                    $this->handleExecutionWindow(
                        symbol: $symbol,
                        timeframe: $timeframe,
                        finalSide: $finalSide,
                        signalsPayload: $signalsPayload,
                        contextSignals: $contextSignals,
                        meta: $meta,
                        eligibility: $eligibility,
                        contextTrail: $contextTrail
                    );
                } else {
                    $contextTrail[] = ['step' => 'final_side_none'];
                    $this->validationLogger->info('Final decision not actionable', [
                        'symbol' => $symbol, 'timeframe' => $timeframe,
                        'final_signal' => $signalsPayload['final']['signal'] ?? 'NONE',
                        'trail' => $contextTrail, 'meta' => $this->compactMeta($meta),
                    ]);
                }
            } elseif ($isValid) {
                $contextTrail[] = ['step' => 'window_not_eligible'];
                $this->validationLogger->info('Window not eligible for opening malgré une décision valide', [
                    'symbol' => $symbol, 'timeframe' => $timeframe,
                    'final_signal' => $signalsPayload['final']['signal'] ?? 'NONE',
                    'trail' => $contextTrail, 'meta' => $this->compactMeta($meta),
                ]);
            }
        }

        // === Auto-réparation (proc stockée) ===
        $hasOpenOrder    = method_exists($this->mtfStateService, 'hasOpenOrder')    ? (int) $this->mtfStateService->hasOpenOrder($symbol)    : 0;
        $hasOpenPosition = method_exists($this->mtfStateService, 'hasOpenPosition') ? (int) $this->mtfStateService->hasOpenPosition($symbol) : 0;
        try {
            $this->connection->beginTransaction();
            $this->connection->executeStatement(
                'CALL sp_post_callback_fix(?, ?, ?, ?)',
                [$symbol, $timeframe, $hasOpenOrder, $hasOpenPosition]
            );
            $this->connection->commit();
            $this->logger->info('sp_post_callback_fix applied', [
                'symbol' => $symbol, 'tf' => $timeframe,
                'has_open_order' => $hasOpenOrder, 'has_open_position' => $hasOpenPosition,
                'meta' => $this->compactMeta($meta),
            ]);
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $this->logger->error('sp_post_callback_fix failed', [
                'symbol' => $symbol, 'tf' => $timeframe, 'error' => $e->getMessage(),
                'meta' => $this->compactMeta($meta),
            ]);
        }

        // === Retour standard ===
        return [
            'status'    => ($signalsPayload[$timeframe]['signal'] ?? 'NONE') === 'NONE' ? 'KO' : 'ok',
            'symbol'    => $symbol,
            'timeframe' => $timeframe,
            'persisted' => $affected,
            'signals'   => $signalsPayload,
            'decision'  => $signalsPayload['final'] ?? null,
            'meta'      => $this->compactMeta($meta),
        ];
    }

    // ----------------- Helpers privés (extraits & inchangés) -----------------

    private function isInsufficientBalanceError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        if ($message === '') return false;
        return str_contains($message, 'insufficient balance')
            || str_contains($message, 'balance not sufficient')
            || str_contains($message, 'balance not enough')
            || str_contains($message, 'insufficient margin')
            || str_contains($message, 'margin not enough');
    }

    private function buildKnownSignals(array $snapshot): array
    {
        $known = [];
        foreach ($snapshot as $tf => $row) {
            $known[$tf] = ['signal' => strtoupper((string)($row['signal'] ?? 'NONE'))];
        }
        return $known;
    }

    private function buildDecisionSignals(array $snapshot): array
    {
        $decision = [];
        foreach ($snapshot as $tf => $row) {
            $decision[$tf] = ['signal' => strtoupper((string)($row['signal'] ?? 'NONE'))];
        }
        return $decision;
    }

    private function buildContextSignals(array $snapshot, array $signalsPayload): array
    {
        $context = [];
        foreach ($snapshot as $tf => $row) {
            $metaSignals = $row['meta']['signals_payload'][$tf] ?? null;
            if (is_array($metaSignals)) {
                $context[$tf] = $metaSignals;
            } else {
                $context[$tf] = ['signal' => strtoupper((string)($row['signal'] ?? 'NONE'))];
            }
            if (!isset($context[$tf]['signal'])) {
                $context[$tf]['signal'] = strtoupper((string)($row['signal'] ?? 'NONE'));
            }
        }
        foreach ($signalsPayload as $key => $data) {
            if (!is_array($data) || !isset($data['signal'])) continue;
            $context[$key] = $data;
        }
        return $context;
    }

    private function buildFactsFromSignals(array $signalsPayload, string $timeframe, array $knownSignals, array $extraMeta = []): array
    {
        $side   = strtoupper($signalsPayload[$timeframe]['signal'] ?? 'NONE');
        $status = strtoupper($signalsPayload['status'] ?? 'FAILED');
        $passed = $status !== 'FAILED';
        $score  = $signalsPayload[$timeframe]['score'] ?? null;

        return [
            'passed' => $passed,
            'side'   => $side,
            'score'  => is_numeric($score) ? (float)$score : null,
            'meta'   => array_merge([
                'signals_payload' => $signalsPayload,
                'known_signals'   => $knownSignals,
            ], $extraMeta),
        ];
    }

    private function isExecutionLocked(array $eligibility, array $tfs): bool
    {
        foreach ($tfs as $tf) {
            $status = strtoupper((string)($eligibility[$tf]['status'] ?? ''));
            if (in_array($status, ['LOCKED_POSITION','LOCKED_ORDER'], true)) {
                return true;
            }
        }
        return false;
    }

    private function executionTimeframes(string $timeframe, bool $includeParent = false): array
    {
        $mapping = [
            '1m' => ['1m'],
            '5m' => ['5m'],
            '15m' => ['15m'],
            '1h' => ['1h'],
            '4h' => ['4h'],
        ];
        $tfs = $mapping[$timeframe] ?? [$timeframe];
        if ($includeParent) {
            $parent = $this->slotService->parentOf($timeframe);
            if ($parent) { $tfs[] = $parent; }
        }
        return $tfs;
    }

    private function buildEventId(string $type, string $symbol, string $tf, string $side): string
    {
        return sprintf('%s|%s|%s|%s|%d', $type, strtoupper($symbol), $tf, $side, (int)(microtime(true)*1000));
    }

    private function compactMeta(array $meta): array
    {
        return [
            'batch_id'   => (string)($meta['batch_id'] ?? ''),
            'request_id' => (string)($meta['request_id'] ?? ''),
            'root_tf'    => (string)($meta['root_tf'] ?? ''),
            'parent_tf'  => $meta['parent_tf'] ?? null,
            'source'     => (string)($meta['source'] ?? 'runner'),
            'pipeline'   => $meta['pipeline'] ?? null,
        ];
    }

    private function handleExecutionWindow(
        string $symbol,
        string $timeframe,
        string $finalSide,
        array $signalsPayload,
        array $contextSignals,
        array $meta,
        array $eligibility,
        array &$contextTrail
    ): void {
        $ctx = $contextSignals;
        $executionTfs = $this->executionTimeframes($timeframe);
        $shouldLock = false;

        // High Conviction (désactivé par défaut)
        $metrics = null;
        try {
            $built   = $this->hcMetricsBuilder->buildForSymbol(
                symbol: $symbol,
                signals: $ctx,
                sideUpper: $finalSide,
                entry: null,
                riskMaxPct: 0.07,
                rMultiple: 2.0
            );
            $metrics = $built['metrics'];
        } catch (\Throwable $e) {
            $this->validationLogger->warning('HC metrics builder failed, skipping order', [
                'symbol' => $symbol, 'timeframe' => $timeframe, 'error' => $e->getMessage(), 'trail' => $contextTrail,
            ]);
        }

        $hcResult = ['ok' => false, 'flags' => []];
        if ($metrics !== null) {
            $hcResult = $this->highConviction->validate($ctx, $metrics);
        }
        $isHigh = (bool)($hcResult['ok'] ?? false);
        $levCap = (int)($hcResult['flags']['leverage_cap'] ?? 0);
        $contextTrail[] = ['step' => 'hc_result', 'is_high' => $isHigh, 'leverage_cap' => $levCap];

        $this->hcTraceWriter->record([
            'symbol'       => $symbol,
            'timeframe'    => $timeframe,
            'evaluated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'signals'      => $ctx,
            'metrics'      => $metrics,
            'validation'   => $hcResult,
            'trail'        => $contextTrail,
        ]);

        // === Mode scalping (par défaut) ===
        $config = $this->userConfigRepository->getOrCreateDefault();
        $contextTrail[] = ['step' => 'opening_scalping'];
        $this->validationLogger->info('Opening position [SCALPING]', [
            'symbol' => $symbol, 'final_side' => $finalSide,
            'signal' => $contextSignals[$timeframe]['signal'] ?? 'NONE',
            'config' => [
                'margin_usdt' => $config->getScalpMarginUsdt(),
                'risk_max_pct' => $config->getScalpRiskMaxPct(),
                'r_multiple' => $config->getScalpRMultiple(),
            ],
            'trail' => $contextTrail,
        ]);

        $scalpTrigger = $this->scalpModeTrigger->evaluate(
            $symbol,
            $timeframe,
            $signalsPayload,
            [
                'timeframe' => $timeframe,
                'final_side' => $finalSide,
                'meta_payload' => $meta,
                'margin_usdt' => 10.0,
            ]
        );

        try {
            if ($scalpTrigger !== null) {
                $contextTrail[] = [
                    'step' => 'scalp_trigger_active',
                    'conditions' => array_map(
                        static fn(array $row) => $row['condition'] ?? 'unknown',
                        $scalpTrigger['conditions'] ?? []
                    ),
                ];
                $this->validationLogger->info('Scalp trigger satisfied, applying overrides', [
                    'symbol' => $symbol, 'timeframe' => $timeframe,
                    'overrides' => $scalpTrigger['overrides'] ?? [],
                    'trail' => $contextTrail,
                ]);
                $this->positionOpener->openScalpTriggerOrder(
                    symbol: $symbol,
                    finalSideUpper: $finalSide,
                    triggerContext: $scalpTrigger
                );
            } else {
                $contextTrail[] = ['step' => 'scalp_trigger_skipped'];
                $this->validationLogger->info('Scalp trigger not satisfied, opening standard scalping order', [
                    'symbol' => $symbol, 'timeframe' => $timeframe, 'trail' => $contextTrail,
                ]);
                $this->positionOpener->openLimitAutoLevWithSr(
                    symbol:         $symbol,
                    finalSideUpper: $finalSide,
                    marginUsdt:     70, // ou $config->getScalpMarginUsdt()
                    riskMaxPct:     $config->getScalpRiskMaxPct(),
                    rMultiple:      $config->getScalpRMultiple()
                );
            }
            $contextTrail[] = ['step' => 'order_submitted', 'type' => 'scalping'];
            $shouldLock = true;
        } catch (LeverageLowException $exception) {
            $this->validationLogger->error('Leverage balance [SCALPING]', [
                'symbol' => $symbol, 'error' => $exception->getMessage(), 'trail' => $contextTrail,
            ]);
        } catch (\Throwable $e) {
            $contextTrail[] = ['step' => 'order_failed', 'type' => 'scalping', 'error' => $e->getMessage()];
            $this->validationLogger->error('Order submission failed [SCALPING]', [
                'symbol' => $symbol, 'error' => $e->getMessage(), 'trail' => $contextTrail,
            ]);
        }

        if ($shouldLock) {
            $eventId = $this->buildEventId('order', $symbol, $timeframe, $finalSide);
            $this->mtfStateService->applyOrderPlaced($eventId, $symbol, $executionTfs ?? $this->executionTimeframes($timeframe));
        }
    }
}
