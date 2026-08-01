<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution;

use App\Common\Enum\Exchange;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Policy\IdempotencyPolicy;
use App\Trading\Paper\Execution\Fake\PaperFakeDispatchResult;
use App\Trading\Paper\Execution\Fake\PaperFakeEffectDispatcher;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntime;
use App\Trading\Paper\Execution\Fake\PaperFakeRuntimeFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Execution\Market\PaperMarketEffectCodec;
use App\Trading\Paper\Execution\Persistence\PaperExecutionStoreInterface;
use App\Trading\Paper\Execution\Persistence\PaperOrderIntentRecorderInterface;
use App\Trading\Paper\Execution\Persistence\PaperSourceClaim;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Strategy\PaperPreparedDecision;
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
    ) {
        $this->crashInjector = $crashInjector === null ? null : \Closure::fromCallable($crashInjector);
        $this->marketCodec = $marketCodec ?? new PaperMarketEffectCodec();
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

        $this->reconcilePending($cell, $runtime);
        $this->restoreAcknowledgedMarket($cell);

        $checkpoint = $this->store->checkpoint($cell);
        if ($sourcePosition < $checkpoint->nextSourcePosition) {
            $this->store->claimSource($cell, $sourcePosition, $event);

            return;
        }
        if ($checkpoint->killed) {
            throw new \LogicException('paper_execution_cell_killed');
        }

        $snapshot = $this->market->events();
        try {
            $this->market->apply($event);
            $prepared = $this->strategy->prepareFor($cell, $event);
        } finally {
            $this->market->restore($snapshot);
        }

        $provenance = $cell->provenance($eligibility);
        $marketEffectKey = 'sha256:' . hash('sha256', CanonicalJson::encode([
            'cell_id' => $cell->id,
            'source_event_id' => $event->eventId,
            'effect_type' => 'market_event',
        ]));
        $tradeEffectKey = null;
        $identity = null;
        if ($prepared !== null && $prepared->plan !== null) {
            $prepared->lifecycle->merge($provenance + [
                'paper_source_event_id' => $event->eventId,
                'paper_dataset_id' => $datasetId,
            ]);
            $identity = ['client_order_id' => (new IdempotencyPolicy())->clientOrderIdFromDecisionKey(
                $cell->id . '|' . $event->eventId . '|' . $prepared->decisionKey,
            )];
            $tradeEffectKey = 'sha256:' . hash('sha256', CanonicalJson::encode([
                'cell_id' => $cell->id,
                'source_event_id' => $event->eventId,
                'decision_key' => $prepared->decisionKey,
                'effect_type' => 'trade_entry',
            ]));
        }

        $this->crash(PaperCrashPoint::BEFORE_PHASE_1_COMMIT);
        $decision = null;
        $claim = $this->store->transactional(function () use ($cell, $sourcePosition, $event, $marketEffectKey, $tradeEffectKey, $prepared, $identity, $provenance, &$decision): PaperSourceClaim {
            $claim = $this->store->claimSource($cell, $sourcePosition, $event);
            if ($claim->status === PaperSourceClaim::ACCEPTED) {
                $this->store->appendEffect($cell, $sourcePosition, $marketEffectKey, $this->marketCodec->encode($event));
                if ($prepared !== null && $prepared->plan !== null && $identity !== null && $tradeEffectKey !== null) {
                    $durableIdentity = $this->orderIntents->reserve($prepared, $identity, $provenance);
                    $decision = new PaperPreparedDecision($prepared, $durableIdentity, $provenance);
                    $this->store->appendEffect($cell, $sourcePosition, $tradeEffectKey, $this->codec->encode($prepared, $durableIdentity, $provenance));
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

        $this->reconcilePending($cell, $runtime, false);
        $this->market->apply($event);
    }

    public function counters(PaperExecutionCell $cell): PaperExecutionCounters
    {
        return PaperExecutionCounters::fromJournal($this->store->journalEventCounts($cell));
    }

    private function reconcilePending(PaperExecutionCell $cell, PaperFakeRuntime $runtime, bool $retry = true): void
    {
        foreach ($this->store->pendingEffects($cell) as $pending) {
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

    private function restoreAcknowledgedMarket(PaperExecutionCell $cell, bool $force = false): void
    {
        if (!$force && $this->restoredCellId === $cell->id) {
            return;
        }
        $this->market->restore($this->store->acknowledgedSources($cell));
        $this->restoredCellId = $cell->id;
    }

    private function assertInput(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, string $datasetId, PaperMarketEvent $event): void
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $datasetId)) {
            throw new \InvalidArgumentException('paper_execution_dataset_id_invalid');
        }
        $cell->provenance($eligibility);
        $this->databaseGuard->assertReady($this->environment);
        $context = new PaperRuntimeContext('paper', Exchange::FAKE, $this->enabled, false, false, [$event->symbol], $cell);
        $this->runtimeGuard->assertSafe($context);
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

    private function crash(PaperCrashPoint $point): void
    {
        if ($this->crashInjector !== null) {
            ($this->crashInjector)($point);
        }
    }
}
