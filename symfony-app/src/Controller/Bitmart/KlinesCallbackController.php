<?php

namespace App\Controller\Bitmart;

use App\Bitmart\Http\BitmartHttpClientPublic;
use App\Entity\Contract;
use App\Repository\BlacklistedContractRepository;
use App\Repository\KlineRepository;
use App\Service\Exception\Trade\Position\LeverageLowException;
use App\Service\Persister\KlinePersister;
use App\Service\Pipeline\CallbackEvalService;
use App\Service\Pipeline\MtfDecisionService;
use App\Service\Pipeline\MtfSignalStore;
use App\Service\Pipeline\MtfStateService;
use App\Service\Pipeline\PipelineMeta;
use App\Service\Pipeline\SlotService;
use App\Service\Signals\Timeframe\SignalService;
use App\Service\Trading\PositionOpener;
use App\Service\Trading\ScalpModeTriggerService;
use App\Service\Trading\BitmartAccountGateway;
use App\Service\Bitmart\BitmartRefreshService;
use App\Service\Signals\HighConviction\HighConvictionMetricsBuilder;
use App\Service\Strategy\HighConvictionValidation;
use App\Service\Strategy\HighConvictionTraceWriter;
use App\Repository\RuntimeGuardRepository;
use App\Repository\UserConfigRepository;
use App\Util\TimeframeHelper;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class KlinesCallbackController extends AbstractController
{
    private const LIMIT_KLINES = 270; // fallback si non fourni

    private readonly HighConvictionValidation $highConviction;
    private readonly BitmartAccountGateway $bitmartAccount;
    private readonly HighConvictionTraceWriter $hcTraceWriter;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MtfSignalStore $signalStore,
        private readonly MtfDecisionService $decisionService,
        private readonly MtfStateService $mtfStateService,
        private readonly KlineRepository $klineRepository,
        private readonly PositionOpener $positionOpener,
        private readonly ScalpModeTriggerService $scalpModeTrigger,
        private readonly RuntimeGuardRepository $runtimeGuardRepository,
        private readonly HighConvictionMetricsBuilder $hcMetricsBuilder,
        private readonly UserConfigRepository $userConfigRepository,
        private readonly Connection $connection,
        HighConvictionValidation $highConviction,
        HighConvictionTraceWriter $hcTraceWriter,
        BitmartAccountGateway $bitmartAccount,
        private readonly BitmartHttpClientPublic $bitmart,
        private readonly KlinePersister $persister,
        private readonly CallbackEvalService $callbackEval,
        private readonly SlotService $slotService,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $validationLogger,
        private readonly SignalService $signalService,
        private readonly BitmartRefreshService $refreshService,
        private readonly BlacklistedContractRepository $blacklistedContractRepository,
    ) {
        $this->highConviction = $highConviction;
        $this->bitmartAccount = $bitmartAccount;
        $this->hcTraceWriter = $hcTraceWriter;
    }

    #[Route('/api/callback/bitmart/get-kline', name: 'bitmart_klines_callback', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->runtimeGuardRepository->isPaused()) {
            return new JsonResponse(['status' => 'paused'], 200);
        }

        $envelope = json_decode($request->getContent(), true) ?? [];
        $payload  = $envelope['params'] ?? [];

        $symbol    = (string) ($payload['contract']  ?? '');
        $timeframe = strtolower((string) ($payload['timeframe'] ?? '4h'));
        $limit     = (int)    ($payload['limit']     ?? self::LIMIT_KLINES);

        $meta              = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $metaForPipeline   = $meta['pipeline'] ?? null;
        $metaBatchId       = (string)($meta['batch_id']   ?? '');
        $metaRequestId     = (string)($meta['request_id'] ?? '');
        $metaRootTf        = (string)($meta['root_tf']    ?? $timeframe);
        $metaParentTf      = $meta['parent_tf'] ?? null;
        $metaSource        = (string)($meta['source'] ?? 'unknown');

        if ($symbol === '') {
            return $this->jsonError('Missing contract symbol', 400);
        }

        /** @var Contract|null $contract */
        $contract = $this->em->getRepository(Contract::class)->findOneBy(['symbol' => $symbol]);
        if (!$contract) {
            return $this->jsonError('Contract not found: '.$symbol, 404);
        }

        // Normalise le pas → minutes (Futures V2 attend des minutes côté REST)
        $stepMinutes = TimeframeHelper::parseTimeframeToMinutes($timeframe);

        // Fetch des dernières bougies clôturées
        $klinesDto = $this->bitmart->getFuturesKlines(
            symbol: $symbol,
            step:   $stepMinutes,
            fromTs: null,
            toTs:   null,
            limit:  $limit
        );

        // Persist
        $affected = $this->persister->upsertMany($contract, $stepMinutes, $klinesDto);

        // Lookback suffisant pour évaluation
        $lookback = max(260, $limit);
        $klines   = $this->klineRepository->findRecentBySymbolAndTimeframe(
            $contract->getSymbol(),
            $timeframe,
            $lookback
        );
        $klines   = array_values($klines);

        // Cutoff debug
        $cutoff = TimeframeHelper::getAlignedOpenByMinutes($stepMinutes);
        $lastPersisted = null;
        if ($klines) {
            $last = $klines[count($klines) - 1];
            $lastPersisted = method_exists($last, 'getTimestamp') ? $last->getTimestamp() : null;
        }
        $this->logger->info('Klines cutoff control', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'step_minutes' => $stepMinutes,
            'cutoff_ts' => $cutoff->getTimestamp(),
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            'last_persisted_ts' => $lastPersisted ? $lastPersisted->getTimestamp() : null,
            'last_persisted' => $lastPersisted ? $lastPersisted->format('Y-m-d H:i:s') : null,
            'has_non_closed' => $lastPersisted ? ($lastPersisted->getTimestamp() >= $cutoff->getTimestamp()) : null,
            'persisted_count' => count($klines),
            'meta' => [
                'batch_id' => $metaBatchId,
                'request_id' => $metaRequestId,
                'root_tf' => $metaRootTf,
                'parent_tf' => $metaParentTf,
                'source' => $metaSource,
                'pipeline' => $metaForPipeline,
            ],
        ]);

        // Stale \=\> re\-refresh
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $staleThreshold = $now->modify('-'.$stepMinutes.' minutes');
        $isBlacklisted = $this->blacklistedContractRepository->isBlacklisted($symbol);
        if ($lastPersisted && $lastPersisted < $staleThreshold && !$isBlacklisted) {
            $this->logger->warning('Last kline is stale, triggering refresh and skipping evaluation', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'last_persisted' => $lastPersisted->format('Y-m-d H:i:s'),
                'threshold' => $staleThreshold->format('Y-m-d H:i:s'),
                'meta' => ['batch_id' => $metaBatchId, 'request_id' => $metaRequestId],
            ]);
            $this->refreshService->refreshSingle($symbol, $timeframe, $limit);
            return new JsonResponse([
                'status' => 'refreshed',
                'reason' => 'stale_last_kline',
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'meta' => ['batch_id' => $metaBatchId, 'request_id' => $metaRequestId],
            ], 202);
        }

        $slot = $this->slotService->currentSlot($timeframe, $now);
        if (!$this->callbackEval->ensureParentFresh($symbol, $timeframe, $slot)) {
            return new JsonResponse([
                'status' => 'pending_parent',
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'meta' => ['batch_id' => $metaBatchId, 'request_id' => $metaRequestId],
            ], 202);
        }

        $previousSnapshot = $this->signalStore->fetchLatestSignals($symbol);
        $knownSignals = $this->buildKnownSignals($previousSnapshot);

        // Évaluation TF courant
        $result = $this->signalService->evaluate($timeframe, $klines, $knownSignals);

        $signalsPayload = $result['signals'] ?? [];
        $signalsPayload[$timeframe] = $signalsPayload[$timeframe] ?? ['signal' => 'NONE'];
        $signalsPayload['final']  = $result['final']  ?? ['signal' => 'NONE'];
        $signalsPayload['status'] = $result['status'] ?? 'UNKNOWN';

        // Enrichit les faits avec le meta d’enveloppe
        $facts = $this->buildFactsFromSignals(
            $signalsPayload,
            $timeframe,
            $knownSignals,
            [
                'envelope_meta' => [
                    'batch_id' => $metaBatchId,
                    'request_id' => $metaRequestId,
                    'root_tf' => $metaRootTf,
                    'parent_tf' => $metaParentTf,
                    'source' => $metaSource,
                    'pipeline' => $metaForPipeline,
                ],
            ]
        );
        $this->callbackEval->persistEvaluation($symbol, $timeframe, $slot, $facts);

        $latestSnapshot = $this->signalStore->fetchLatestSignals($symbol);
        $eligibility    = $this->signalStore->fetchEligibility($symbol);
        $decisionSignals = $this->buildDecisionSignals($latestSnapshot);
        $decision       = $this->decisionService->decide($timeframe, $decisionSignals);
        $isValid        = $decision['is_valid'];
        $canEnter       = $decision['can_enter'];
        $contextSignals = $this->buildContextSignals($latestSnapshot, $signalsPayload);

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
            'meta' => ['batch_id' => $metaBatchId, 'request_id' => $metaRequestId],
        ]);

        // Décision \+ ouverture potentielle
        $finalSide = strtoupper($signalsPayload['final']['signal'] ?? $signalsPayload[$timeframe]['signal'] ?? 'NONE');
        if (!($timeframe === '4h' && $finalSide === 'NONE')) {
            $contextTrail = [];
            $contextTrail[] = ['step' => 'decision_applied', 'timeframe' => $timeframe, 'is_valid' => $isValid, 'can_enter' => $canEnter];

            $tfOpenable = ['5m','1m'];
            $eligibilityLocked = $this->isExecutionLocked($eligibility, $this->executionTimeframes($timeframe));
            if ($metaForPipeline === PipelineMeta::DONT_INC_DEC_DEL) {
                $contextTrail[] = ['step' => 'meta_skip'];
                $this->validationLogger->info('Skipping window due to meta flag', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'trail' => $contextTrail,
                    'meta' => ['batch_id' => $metaBatchId, 'request_id' => $metaRequestId],
                ]);
            } elseif ($canEnter && in_array($timeframe, $tfOpenable, true) && !$eligibilityLocked) {
                $contextTrail[] = ['step' => 'window_eligible'];
                $this->validationLogger->info('Window eligible for order opening', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'trail' => $contextTrail,
                    'meta' => ['batch_id' => $metaBatchId, 'request_id' => $metaRequestId],
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
                        'symbol' => $symbol,
                        'timeframe' => $timeframe,
                        'final_signal' => $signalsPayload['final']['signal'] ?? 'NONE',
                        'trail' => $contextTrail,
                        'meta' => ['batch_id' => $metaBatchId, 'request_id' => $metaRequestId],
                    ]);
                }
            } elseif ($isValid) {
                $contextTrail[] = ['step' => 'window_not_eligible'];
                $this->validationLogger->info('Window not eligible for opening malgré une décision valide', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'final_signal' => $signalsPayload['final']['signal'] ?? 'NONE',
                    'trail' => $contextTrail,
                    'meta' => ['batch_id' => $metaBatchId, 'request_id' => $metaRequestId],
                ]);
            }
        }

        $this->logger->info('Klines persisted + evaluated', [
            'symbol'       => $symbol,
            'timeframe'    => $timeframe,
            'step_minutes' => $stepMinutes,
            'affected'     => $affected,
            'meta'         => ['batch_id' => $metaBatchId, 'request_id' => $metaRequestId],
        ]);

        if (($signalsPayload[$timeframe]['signal'] ?? 'NONE') === 'NONE') {
            return new JsonResponse([
                'status'    => 'KO',
                'symbol'    => $symbol,
                'timeframe' => $timeframe,
                'persisted' => $affected,
                'window'    => ['limit' => $limit],
                'signals'   => $signalsPayload,
                'decision'  => $signalsPayload['final'] ?? null,
                'meta'      => ['batch_id' => $metaBatchId, 'request_id' => $metaRequestId],
            ]);
        }

        // Auto\-réparation post\-callback
        $hasOpenOrder     = method_exists($this->mtfStateService, 'hasOpenOrder')     ? (int) $this->mtfStateService->hasOpenOrder($symbol)     : 0;
        $hasOpenPosition  = method_exists($this->mtfStateService, 'hasOpenPosition')  ? (int) $this->mtfStateService->hasOpenPosition($symbol)  : 0;

        try {
            $this->em->getConnection()->beginTransaction();
            $this->em->getConnection()->executeStatement(
                'CALL sp_post_callback_fix(?, ?, ?, ?)',
                [$symbol, $timeframe, $hasOpenOrder, $hasOpenPosition]
            );
            $this->em->getConnection()->commit();
            $this->logger->info('sp_post_callback_fix applied', [
                'symbol' => $symbol, 'tf' => $timeframe,
                'has_open_order' => $hasOpenOrder, 'has_open_position' => $hasOpenPosition,
                'meta' => ['batch_id' => $metaBatchId, 'request_id' => $metaRequestId],
            ]);
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            $this->logger->error('sp_post_callback_fix failed', [
                'symbol' => $symbol, 'tf' => $timeframe, 'error' => $e->getMessage(),
                'meta' => ['batch_id' => $metaBatchId, 'request_id' => $metaRequestId],
            ]);
        }

        return new JsonResponse([
            'status'    => 'ok',
            'symbol'    => $symbol,
            'timeframe' => $timeframe,
            'persisted' => $affected,
            'window'    => ['limit' => $limit],
            'signals'   => $signalsPayload,
            'decision'  => $signalsPayload['final'] ?? null,
            'meta'      => [
                'batch_id' => $metaBatchId,
                'request_id' => $metaRequestId,
                'root_tf' => $metaRootTf,
                'parent_tf' => $metaParentTf,
                'source' => $metaSource,
            ],
        ]);
    }

    private function isInsufficientBalanceError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        if ($message === '') {
            return false;
        }

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
            if (!is_array($data) || !isset($data['signal'])) {
                continue;
            }
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

    private function buildEventId(string $type, string $symbol, string $tf, string $side): string
    {
        return sprintf('%s|%s|%s|%s|%d', $type, strtoupper($symbol), $tf, $side, microtime(true) * 1000);
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

        try {
            $built = $this->hcMetricsBuilder->buildForSymbol(
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
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'error' => $e->getMessage(),
                'trail' => $contextTrail,
            ]);
            $metrics = null;
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

        $isHigh = false; // HC désactivé présentement
        if ($isHigh && $levCap > 0 && $metrics !== null) {
            $config = $this->userConfigRepository->getOrCreateDefault();
            $availableUsdt = $this->bitmartAccount->getAvailableUSDT();
            $marginBudget  = max(0.0, $availableUsdt * $config->getHcMarginPct());
            $contextTrail[] = [
                'step' => 'budget_high_conviction',
                'available_usdt' => $availableUsdt,
                'margin_usdt' => $marginBudget,
                'margin_pct' => $config->getHcMarginPct(),
            ];

            if ($marginBudget <= 0.0) {
                $contextTrail[] = ['step' => 'budget_insufficient'];
                $this->validationLogger->warning('Skipping HC opening: available balance is zero', [
                    'symbol' => $symbol,
                    'available_usdt' => $availableUsdt,
                    'trail' => $contextTrail,
                ]);
            } else {
                $contextTrail[] = ['step' => 'opening_high_conviction'];
                try {
                    $this->positionOpener->openLimitHighConvWithSr(
                        symbol:         $symbol,
                        finalSideUpper: $finalSide,
                        leverageCap:    $levCap,
                        marginUsdt:     $marginBudget,
                        riskMaxPct:     $config->getHcRiskMaxPct(),
                        rMultiple:      $config->getHcRMultiple(),
                        meta:           ['ctx' => 'HC'],
                        expireAfterSec: $config->getHcExpireAfterSec()
                    );
                    $contextTrail[] = ['step' => 'order_submitted', 'type' => 'high_conviction'];
                    $shouldLock = true;
                } catch (\Throwable $e) {
                    $insufficient = $this->isInsufficientBalanceError($e);
                    $contextTrail[] = [
                        'step' => 'order_failed',
                        'type' => 'high_conviction',
                        'error' => $e->getMessage(),
                        'insufficient_balance' => $insufficient,
                        'available_usdt' => $availableUsdt,
                        'margin_usdt' => $marginBudget,
                    ];
                    $loggerContext = [
                        'symbol' => $symbol,
                        'error' => $e->getMessage(),
                        'available_usdt' => $availableUsdt,
                        'margin_usdt' => $marginBudget,
                        'trail' => $contextTrail,
                    ];
                    if ($insufficient) {
                        $this->validationLogger->warning('Insufficient balance, skipping order [HIGH_CONVICTION]', $loggerContext);
                    } else {
                        $this->validationLogger->error('Order submission failed [HIGH_CONVICTION]', $loggerContext);
                    }
                }
            }
        } else {
            $config = $this->userConfigRepository->getOrCreateDefault();
            $contextTrail[] = ['step' => 'opening_scalping'];
            $this->validationLogger->info('Opening position [SCALPING]', [
                'symbol' => $symbol,
                'final_side' => $finalSide,
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
                        'symbol' => $symbol,
                        'timeframe' => $timeframe,
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
                        'symbol' => $symbol,
                        'timeframe' => $timeframe,
                        'trail' => $contextTrail,
                    ]);
                    $this->positionOpener->openLimitAutoLevWithSr(
                        symbol:         $symbol,
                        finalSideUpper: $finalSide,
                        marginUsdt:     40,//$config->getScalpMarginUsdt(),
                        riskMaxPct:     $config->getScalpRiskMaxPct(),
                        rMultiple:      $config->getScalpRMultiple()
                    );
                }
                $contextTrail[] = ['step' => 'order_submitted', 'type' => 'scalping'];
                $shouldLock = true;
            } catch (LeverageLowException $exception) {
                $this->validationLogger->error('Leverage balance [SCALPING]', [
                    'symbol' => $symbol,
                    'error' => $exception->getMessage(),
                    'trail' => $contextTrail,
                ]);
            } catch (\Throwable $e) {
                $contextTrail[] = ['step' => 'order_failed', 'type' => 'scalping', 'error' => $e->getMessage()];
                $this->validationLogger->error('Order submission failed [SCALPING]', [
                    'symbol' => $symbol,
                    'error' => $e->getMessage(),
                    'trail' => $contextTrail,
                ]);
            }
        }

        if ($shouldLock) {
            $eventId = $this->buildEventId('order', $symbol, $timeframe, $finalSide);
            $this->mtfStateService->applyOrderPlaced($eventId, $symbol, $executionTfs ?? $this->executionTimeframes($timeframe));
        }
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
            if ($parent) {
                $tfs[] = $parent;
            }
        }
        return $tfs;
    }

    private function jsonError(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'message' => $message], $status);
    }
}
