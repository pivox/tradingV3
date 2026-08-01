<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Fake;

use App\Common\Enum\Exchange;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\TradeEntry\Dto\PreparedTradeEntry;
use App\TradeEntry\Execution\ExchangeExecutionService;
use App\Trading\Paper\Execution\Strategy\PaperPreparedDecision;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final readonly class PaperFakeEffectDispatcher
{
    public function __construct(
        private ExchangeExecutionService $execution,
        private FakeExchangeEventNormalizer $normalizer,
    ) {
    }

    public function prepare(PreparedTradeEntry $prepared): PreparedTradeEntry
    {
        if ($prepared->plan === null) {
            return $prepared;
        }
        $plan = $this->execution->preparePlan(
            $prepared->plan,
            $prepared->mode,
            $prepared->executionTimeframe,
            $prepared->decisionKey,
        );
        $prepared->lifecycle->merge(['paper_standard_plan_prepared' => true]);

        return new PreparedTradeEntry(
            $plan,
            null,
            $prepared->decisionKey,
            $prepared->internalTradeId,
            $prepared->lifecycle,
            $prepared->mode,
            $prepared->executionTimeframe,
            $prepared->preflight,
        );
    }

    public function dispatch(PaperFakeRuntime $runtime, PaperPreparedDecision $decision): PaperFakeDispatchResult
    {
        if ($runtime->adapter->exchange() !== Exchange::FAKE || $decision->prepared->plan === null) {
            throw new \InvalidArgumentException('paper_execution_exchange_must_be_fake');
        }
        if (($decision->prepared->lifecycle->toArray()['paper_standard_plan_prepared'] ?? null) !== true) {
            throw new \LogicException('paper_standard_plan_preparation_required');
        }
        $cursor = $runtime->eventCursor();
        $result = $this->execution->executeOnAdapter(
            $decision->prepared->plan,
            $runtime->adapter,
            $decision->prepared->decisionKey,
            $decision->prepared->mode,
            $decision->prepared->executionTimeframe,
            $decision->orderIntentIdentity['client_order_id'],
            orderIntentId: $decision->orderIntentIdentity['order_intent_id'],
            planPrepared: true,
            executionMetadata: $decision->provenance + $decision->prepared->lifecycle->toArray() + [
                'internal_trade_id' => $decision->prepared->internalTradeId,
                'order_intent_id' => $decision->orderIntentIdentity['order_intent_id'],
            ],
        );

        $normalized = $this->normalizeSince($runtime, $cursor);

        return new PaperFakeDispatchResult(
            $result,
            $normalized,
            ($result->raw['order']['metadata']['idempotent_replay'] ?? false) === true,
        );
    }

    public function dispatchMarket(PaperFakeRuntime $runtime, PaperMarketEvent $event): void
    {
        $runtime->applyMarketEvent($event);
    }

    /** @return list<ExchangeEventInterface> */
    public function normalizeSince(PaperFakeRuntime $runtime, int $cursor): array
    {
        $normalized = [];
        foreach ($runtime->eventsSince($cursor) as $event) {
            foreach ($this->normalizer->normalize($event) as $exchangeEvent) {
                if ($exchangeEvent instanceof ExchangeEventInterface) {
                    $normalized[] = $exchangeEvent;
                }
            }
        }

        return $normalized;
    }
}
