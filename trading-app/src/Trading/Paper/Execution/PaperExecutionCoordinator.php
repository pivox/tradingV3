<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution;

use App\Common\Enum\Exchange;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Policy\IdempotencyPolicy;
use App\Trading\Paper\Execution\Fake\PaperCanonicalFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperFakeDispatchResult;
use App\Trading\Paper\Execution\Fake\PaperFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntime;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Execution\Market\PaperMarketEffectCodec;
use App\Trading\Paper\Execution\Persistence\PaperExecutionStoreInterface;
use App\Trading\Paper\Execution\Persistence\PaperCanonicalOrderIntentRecorderInterface;
use App\Trading\Paper\Execution\Persistence\PaperOrderIntentRecorderInterface;
use App\Trading\Paper\Execution\Persistence\PaperSourceClaim;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Strategy\PaperPreparedDecision;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffect;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodec;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyDecision;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyObservation;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyPreparationResult;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyPreparationInterface;
use App\Trading\Paper\Execution\Strategy\PaperPreparedEffectCodec;
use App\Trading\Paper\Execution\Strategy\PaperStrategyPreparationInterface;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Runtime\PaperDatabaseGuard;
use App\Trading\Paper\Runtime\PaperRuntimeContext;
use App\Trading\Paper\Runtime\PaperRuntimeGuard;

final class PaperExecutionCoordinator implements PaperEventCoordinatorInterface
{
    /** @var \Closure(PaperCrashPoint): void|null */
    private ?\Closure $crashInjector;

    private ?string $restoredCellId = null;

    private readonly PaperMarketEffectCodec $marketCodec;

    /** @param callable(PaperCrashPoint): void|null $crashInjector */
    public function __construct(
        private readonly PaperExecutionStoreInterface $store,
        private readonly PaperMarketStateProjector $market,
        private readonly PaperStrategyPreparationInterface $strategy,
        private readonly PaperPreparedEffectCodec $codec,
        private readonly PaperFakeRuntimeFactory $runtimeFactory,
        private readonly PaperFakeEffectDispatcher $dispatcher,
        private readonly ExchangeLocalProjectionStoreInterface $exchangeProjection,
        private readonly PaperOrderIntentRecorderInterface $orderIntents,
        private readonly PaperRuntimeGuard $runtimeGuard,
        private readonly PaperDatabaseGuard $databaseGuard,
        private readonly string $environment = 'prod',
        private readonly bool $enabled = false,
        ?callable $crashInjector = null,
        ?PaperMarketEffectCodec $marketCodec = null,
        private readonly ?PaperCanonicalStrategyPreparationInterface $canonicalStrategy = null,
        private readonly ?PaperCanonicalPreparedEffectCodec $canonicalCodec = null,
        private readonly ?PaperCanonicalFakeEffectDispatcher $canonicalDispatcher = null,
        private readonly ?PaperCanonicalOrderIntentRecorderInterface $canonicalOrderIntents = null,
    ) {
        $this->crashInjector = $crashInjector === null ? null : \Closure::fromCallable($crashInjector);
        $this->marketCodec = $marketCodec ?? new PaperMarketEffectCodec();
    }

    public function assertReady(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, array $symbols): void
    {
        $cell->provenance($eligibility);
        if ($cell->isModern() && ($this->canonicalStrategy === null
            || $this->canonicalCodec === null
            || $this->canonicalDispatcher === null
            || $this->canonicalOrderIntents === null
        )) {
            throw new \LogicException('paper_modern_strategy_bridge_unavailable');
        }
        $this->databaseGuard->assertReady($this->environment);
        $this->runtimeGuard->assertSafe(new PaperRuntimeContext('paper', Exchange::FAKE, $this->enabled, false, false, $symbols, $cell));
    }

    public function consumeAt(
        PaperExecutionCell $cell,
        PaperProfileEligibility $eligibility,
        string $datasetId,
        int $sourcePosition,
        PaperMarketEvent $event,
    ): void {
        $this->assertInput($cell, $eligibility, $datasetId, $event);
        $runtime = $this->runtimeFactory->forCell($cell);

        if ($this->restoredCellId !== $cell->id) {
            $this->reconcilePending($cell, $runtime);
        }
        $this->restoreAcknowledgedMarket($cell);

        $checkpoint = $this->store->checkpoint($cell);
        if ($sourcePosition < $checkpoint->nextSourcePosition) {
            $this->store->claimSource($cell, $sourcePosition, $event);
            $this->reconcilePending($cell, $runtime, true, $sourcePosition);
            $this->restoreAcknowledgedMarket($cell, true);

            return;
        }
        if ($checkpoint->killed) {
            throw new \LogicException('paper_execution_cell_killed');
        }

        $modern = $cell->isModern();
        $modernModeId = $cell->modernIdentity?->modeId;
        $snapshot = $modern ? null : $this->market->events();
        $eventCountBefore = count($this->market->events());
        $tentativeApplied = false;
        $prepared = null;
        $canonicalDecision = null;
        $canonicalPreparation = null;
        try {
            $this->market->apply($event, !$modern, $modernModeId, false);
            $tentativeApplied = count($this->market->events()) === $eventCountBefore + 1;
            if ($modern) {
                $datasetIdentity = $this->store->datasetIdentity($cell);
                if ($datasetIdentity['dataset_id'] !== $datasetId) {
                    throw new \LogicException('paper_canonical_strategy_dataset_mismatch');
                }
                $sourceBuildVersion = $datasetIdentity['source_build_version'];
                if (!is_string($sourceBuildVersion)) {
                    throw new \LogicException('paper_canonical_strategy_dataset_mismatch');
                }
                $canonicalPreparation = $this->canonicalStrategy()->prepareFor(
                    $cell,
                    $event,
                    $datasetIdentity['dataset_id'],
                    $datasetIdentity['events_file_sha256'],
                    $sourceBuildVersion,
                );
                $canonicalDecision = $canonicalPreparation->decision;
            } else {
                $prepared = $this->strategy->prepareFor($cell, $event);
                if ($prepared !== null) {
                    $prepared = $this->dispatcher->prepare($prepared);
                }
            }
        } finally {
            if ($modern) {
                if ($tentativeApplied) {
                    $this->market->rollbackLastModern($event);
                }
            } else {
                $this->market->restore($snapshot ?? []);
            }
        }

        $provenance = $cell->provenance($eligibility);
        $strategyObservation = $canonicalPreparation instanceof PaperCanonicalStrategyPreparationResult
            ? PaperCanonicalStrategyObservation::fromPreparation($cell, $event, $canonicalPreparation)
            : null;
        $marketEffectKey = 'sha256:' . hash('sha256', CanonicalJson::encode([
            'cell_id' => $cell->id,
            'source_event_id' => $event->eventId,
            'effect_type' => 'market_event',
        ]));
        $tradeEffectKey = null;
        $identity = null;
        $decisionKey = null;
        if ($prepared !== null && $prepared->plan !== null) {
            $prepared->lifecycle->merge($provenance + [
                'paper_source_event_id' => $event->eventId,
                'paper_dataset_id' => $datasetId,
            ]);
            $decisionKey = $prepared->decisionKey;
        } elseif ($canonicalDecision instanceof PaperCanonicalStrategyDecision) {
            if ($canonicalDecision->plan->symbol !== $event->symbol) {
                throw new \LogicException('paper_canonical_strategy_symbol_mismatch');
            }
            $decisionKey = $canonicalDecision->decisionKey;
        }
        if ($decisionKey !== null) {
            $identity = ['client_order_id' => (new IdempotencyPolicy())->clientOrderIdFromDecisionKey(
                $cell->id . '|' . $event->eventId . '|' . $decisionKey,
            )];
            $tradeEffectKey = 'sha256:' . hash('sha256', CanonicalJson::encode([
                'cell_id' => $cell->id,
                'source_event_id' => $event->eventId,
                'decision_key' => $decisionKey,
                'effect_type' => 'trade_entry',
            ]));
            if ($canonicalDecision instanceof PaperCanonicalStrategyDecision) {
                $canonicalDecision->prepare($identity + ['order_intent_id' => 1], $provenance);
            }
        }

        $this->crash(PaperCrashPoint::BEFORE_PHASE_1_COMMIT);
        $decision = null;
        $canonicalEffect = null;
        $claim = $this->store->transactional(function () use ($cell, $sourcePosition, $event, $marketEffectKey, $tradeEffectKey, $prepared, $canonicalDecision, $strategyObservation, $identity, $provenance, &$decision, &$canonicalEffect): PaperSourceClaim {
            $claim = $this->store->claimSource($cell, $sourcePosition, $event);
            if ($claim->status === PaperSourceClaim::ACCEPTED) {
                if ($strategyObservation instanceof PaperCanonicalStrategyObservation) {
                    $this->store->appendStrategyObservation($cell, $sourcePosition, $strategyObservation);
                }
                $this->store->appendEffect($cell, $sourcePosition, $marketEffectKey, $this->marketCodec->encode($event));
                if ($prepared !== null && $prepared->plan !== null && $identity !== null && $tradeEffectKey !== null) {
                    $durableIdentity = $this->orderIntents->reserve($prepared, $identity, $provenance);
                    $decision = new PaperPreparedDecision($prepared, $durableIdentity, $provenance);
                    $this->store->appendEffect($cell, $sourcePosition, $tradeEffectKey, $this->codec->encode($prepared, $durableIdentity, $provenance));
                } elseif ($canonicalDecision instanceof PaperCanonicalStrategyDecision && $identity !== null && $tradeEffectKey !== null) {
                    $durableIdentity = $this->canonicalOrderIntents()->reserve(
                        $canonicalDecision->plan,
                        $canonicalDecision->lineage,
                        $canonicalDecision->decisionKey,
                        $canonicalDecision->executionTimeframe,
                        $identity,
                        $provenance,
                    );
                    $canonicalEffect = $canonicalDecision->prepare($durableIdentity, $provenance);
                    $this->store->appendEffect(
                        $cell,
                        $sourcePosition,
                        $tradeEffectKey,
                        $this->canonicalCodec()->encode($canonicalEffect),
                    );
                }
            }

            return $claim;
        });
        $this->crash(PaperCrashPoint::AFTER_PHASE_1_COMMIT);
        if ($claim->status === PaperSourceClaim::REPLAYED) {
            $this->reconcilePending($cell, $runtime);
            $this->restoreAcknowledgedMarket($cell, true);

            return;
        }

        if ($prepared !== null && $prepared->plan !== null && !$decision instanceof PaperPreparedDecision) {
            throw new \LogicException('paper_order_intent_reservation_missing');
        }
        if ($canonicalDecision instanceof PaperCanonicalStrategyDecision
            && !$canonicalEffect instanceof PaperCanonicalPreparedEffect
        ) {
            throw new \LogicException('paper_canonical_order_intent_reservation_missing');
        }

        $this->reconcilePending($cell, $runtime, false, $sourcePosition);
        $this->market->apply($event, !$cell->isModern(), $cell->modernIdentity?->modeId);
    }

    public function counters(PaperExecutionCell $cell): PaperExecutionCounters
    {
        return PaperExecutionCounters::fromJournal($this->store->journalEventCounts($cell));
    }

    private function reconcilePending(
        PaperExecutionCell $cell,
        PaperFakeRuntime $runtime,
        bool $retry = true,
        ?int $sourcePosition = null,
    ): void
    {
        $pendingEffects = $sourcePosition === null
            ? $this->store->pendingEffects($cell)
            : $this->store->pendingEffectsAt($cell, $sourcePosition);
        foreach ($pendingEffects as $pending) {
            if ($this->marketCodec->supports($pending->payload)) {
                $cursor = $this->store->checkpoint($cell)->fakeEventCursor;
                $event = $this->marketCodec->decode($pending->payload);
                if ($retry) {
                    $this->store->recordEffectRetry($cell, $pending->sourcePosition, $pending->effectKey);
                }
                try {
                    $this->dispatcher->dispatchMarket($runtime, $event);
                } catch (\Throwable $exception) {
                    $this->store->recordEffectFailure($cell, $pending->sourcePosition, $pending->effectKey, 'fake_market_dispatch_failed');
                    throw $exception;
                }
                $this->crash(PaperCrashPoint::AFTER_FAKE_EFFECT);
                $this->completeMarketEffect($cell, $pending->sourcePosition, $pending->effectKey, $runtime, $cursor);

                continue;
            }
            if ($this->canonicalCodec !== null && $this->canonicalCodec->supports($pending->payload)) {
                $effect = $this->canonicalCodec->decode($pending->payload);
                $cursor = $this->store->checkpoint($cell)->fakeEventCursor;
                $dispatch = $this->dispatchCanonicalEffect(
                    $cell,
                    $pending->sourcePosition,
                    $pending->effectKey,
                    $runtime,
                    $effect,
                    $retry,
                );
                $this->crash(PaperCrashPoint::AFTER_FAKE_EFFECT);
                $this->completeCanonicalEffect(
                    $cell,
                    $pending->sourcePosition,
                    $pending->effectKey,
                    $runtime,
                    $effect,
                    $dispatch,
                    $cursor,
                );

                continue;
            }
            $decision = $this->codec->decode($pending->payload);
            $cursor = $this->store->checkpoint($cell)->fakeEventCursor;
            $dispatch = $this->dispatchEffect($cell, $pending->sourcePosition, $pending->effectKey, $runtime, $decision, $retry);
            $this->crash(PaperCrashPoint::AFTER_FAKE_EFFECT);
            $this->completeEffect($cell, $pending->sourcePosition, $pending->effectKey, $runtime, $decision, $dispatch, $cursor);
        }
    }

    private function completeMarketEffect(PaperExecutionCell $cell, int $sourcePosition, string $effectKey, PaperFakeRuntime $runtime, int $cursor): void
    {
        $events = $this->dispatcher->normalizeSince($runtime, $cursor);
        $acknowledgement = [
            'effect_type' => 'market_event',
            'event_types' => array_map(static fn (ExchangeEventInterface $event): string => $event->eventType(), $events),
        ];

        $this->crash(PaperCrashPoint::BEFORE_PHASE_3_COMMIT);
        $this->store->transactional(function () use ($cell, $sourcePosition, $effectKey, $runtime, $events, $acknowledgement): void {
            $this->exchangeProjection->projectAtomically($events);
            $this->store->acknowledge($cell, $sourcePosition, $effectKey, $acknowledgement, $runtime->eventCursor());
        });
        $this->crash(PaperCrashPoint::AFTER_PHASE_3_COMMIT);
    }

    private function dispatchEffect(
        PaperExecutionCell $cell,
        int $sourcePosition,
        string $effectKey,
        PaperFakeRuntime $runtime,
        PaperPreparedDecision $decision,
        bool $retry,
    ): PaperFakeDispatchResult {
        if ($retry) {
            $this->store->recordEffectRetry($cell, $sourcePosition, $effectKey);
        }
        try {
            return $this->dispatcher->dispatch($runtime, $decision);
        } catch (\Throwable $exception) {
            $this->store->recordEffectFailure($cell, $sourcePosition, $effectKey, 'fake_effect_dispatch_failed');
            throw $exception;
        }
    }

    private function dispatchCanonicalEffect(
        PaperExecutionCell $cell,
        int $sourcePosition,
        string $effectKey,
        PaperFakeRuntime $runtime,
        PaperCanonicalPreparedEffect $effect,
        bool $retry,
    ): PaperFakeDispatchResult {
        if ($retry) {
            $this->store->recordEffectRetry($cell, $sourcePosition, $effectKey);
        }
        try {
            return $this->canonicalDispatcher()->dispatch($runtime, $effect);
        } catch (\Throwable $exception) {
            $this->store->recordEffectFailure(
                $cell,
                $sourcePosition,
                $effectKey,
                'fake_canonical_effect_dispatch_failed',
            );
            throw $exception;
        }
    }

    private function completeEffect(
        PaperExecutionCell $cell,
        int $sourcePosition,
        string $effectKey,
        PaperFakeRuntime $runtime,
        PaperPreparedDecision $decision,
        PaperFakeDispatchResult $dispatch,
        int $cursor,
    ): void {
        $events = $this->dispatcher->normalizeSince($runtime, $cursor);
        $acknowledgement = $this->acknowledgementPayload($dispatch->execution, $events);

        $this->crash(PaperCrashPoint::BEFORE_PHASE_3_COMMIT);
        $this->store->transactional(function () use ($cell, $sourcePosition, $effectKey, $runtime, $decision, $dispatch, $events, $acknowledgement): void {
            $this->exchangeProjection->projectAtomically($events);
            $this->orderIntents->acknowledge($decision->orderIntentIdentity, $dispatch->execution);
            $this->store->acknowledge($cell, $sourcePosition, $effectKey, $acknowledgement, $runtime->eventCursor());
        });
        $this->crash(PaperCrashPoint::AFTER_PHASE_3_COMMIT);
    }

    private function completeCanonicalEffect(
        PaperExecutionCell $cell,
        int $sourcePosition,
        string $effectKey,
        PaperFakeRuntime $runtime,
        PaperCanonicalPreparedEffect $effect,
        PaperFakeDispatchResult $dispatch,
        int $cursor,
    ): void {
        $events = $this->canonicalDispatcher()->normalizeSince($runtime, $cursor);
        $acknowledgement = $this->acknowledgementPayload($dispatch->execution, $events);

        $this->crash(PaperCrashPoint::BEFORE_PHASE_3_COMMIT);
        $this->store->transactional(function () use ($cell, $sourcePosition, $effectKey, $runtime, $effect, $dispatch, $events, $acknowledgement): void {
            $this->exchangeProjection->projectAtomically($events);
            $this->canonicalOrderIntents()->acknowledge($effect->orderIntentIdentity, $dispatch->execution);
            $this->store->acknowledge(
                $cell,
                $sourcePosition,
                $effectKey,
                $acknowledgement,
                $runtime->eventCursor(),
            );
        });
        $this->crash(PaperCrashPoint::AFTER_PHASE_3_COMMIT);
    }

    private function restoreAcknowledgedMarket(PaperExecutionCell $cell, bool $force = false): void
    {
        if (!$force && $this->restoredCellId === $cell->id) {
            return;
        }
        $this->market->restore(
            $this->store->acknowledgedSources($cell),
            !$cell->isModern(),
            $cell->modernIdentity?->modeId,
        );
        $this->restoredCellId = $cell->id;
    }

    private function assertInput(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, string $datasetId, PaperMarketEvent $event): void
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $datasetId)) {
            throw new \InvalidArgumentException('paper_execution_dataset_id_invalid');
        }
        $this->assertReady($cell, $eligibility, [$event->symbol]);
        $context = new PaperRuntimeContext('paper', Exchange::FAKE, $this->enabled, false, false, [$event->symbol], $cell);
        $this->runtimeGuard->assertEventProvenance($context, $event);
    }

    /**
     * @param list<ExchangeEventInterface> $events
     * @return array<string, mixed>
     */
    private function acknowledgementPayload(ExecutionResult $result, array $events): array
    {
        return [
            'status' => $result->status,
            'client_order_id' => $result->clientOrderId,
            'exchange_order_id' => $result->exchangeOrderId,
            'event_types' => array_map(static fn (ExchangeEventInterface $event): string => $event->eventType(), $events),
        ];
    }

    private function canonicalStrategy(): PaperCanonicalStrategyPreparationInterface
    {
        return $this->canonicalStrategy
            ?? throw new \LogicException('paper_modern_strategy_bridge_unavailable');
    }

    private function canonicalCodec(): PaperCanonicalPreparedEffectCodec
    {
        return $this->canonicalCodec
            ?? throw new \LogicException('paper_modern_strategy_bridge_unavailable');
    }

    private function canonicalDispatcher(): PaperCanonicalFakeEffectDispatcher
    {
        return $this->canonicalDispatcher
            ?? throw new \LogicException('paper_modern_strategy_bridge_unavailable');
    }

    private function canonicalOrderIntents(): PaperCanonicalOrderIntentRecorderInterface
    {
        return $this->canonicalOrderIntents
            ?? throw new \LogicException('paper_modern_strategy_bridge_unavailable');
    }

    private function crash(PaperCrashPoint $point): void
    {
        if ($this->crashInjector !== null) {
            ($this->crashInjector)($point);
        }
    }
}
