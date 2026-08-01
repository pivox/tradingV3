<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Fake;

use App\Common\Enum\Exchange;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\TradeEntry\Execution\ExchangeExecutionService;
use App\Trading\Paper\Execution\Strategy\PaperPreparedDecision;

final readonly class PaperFakeEffectDispatcher
{
    public function __construct(
        private ExchangeExecutionService $execution,
        private FakeExchangeEventNormalizer $normalizer,
    ) {
    }

    public function dispatch(PaperFakeRuntime $runtime, PaperPreparedDecision $decision): PaperFakeDispatchResult
    {
        if ($runtime->adapter->exchange() !== Exchange::FAKE || $decision->prepared->plan === null) {
            throw new \InvalidArgumentException('paper_execution_exchange_must_be_fake');
        }
        $cursor = $runtime->eventCursor();
        $result = $this->execution->executeOnAdapter(
            $decision->prepared->plan,
            $runtime->adapter,
            $decision->prepared->decisionKey,
            $decision->prepared->mode,
            $decision->prepared->executionTimeframe,
            $decision->orderIntentIdentity['client_order_id'],
            planPrepared: true,
        );

        $normalized = [];
        foreach ($runtime->eventsSince($cursor) as $event) {
            foreach ($this->normalizer->normalize($event) as $exchangeEvent) {
                if ($exchangeEvent instanceof ExchangeEventInterface) {
                    $normalized[] = $exchangeEvent;
                }
            }
        }

        return new PaperFakeDispatchResult(
            $result,
            $normalized,
            ($result->raw['order']['metadata']['idempotent_replay'] ?? false) === true,
        );
    }
}
