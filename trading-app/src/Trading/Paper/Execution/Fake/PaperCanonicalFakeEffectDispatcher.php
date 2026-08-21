<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\TradeEntry\Dto\ExecutionResult;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffect;
use App\Trading\Paper\MarketData\PaperMarketEventRedactor;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;

final readonly class PaperCanonicalFakeEffectDispatcher
{
    public function __construct(private FakeExchangeEventNormalizer $normalizer)
    {
    }

    public function dispatch(
        PaperFakeRuntime $runtime,
        PaperCanonicalPreparedEffect $effect,
    ): PaperFakeDispatchResult {
        try {
            $this->assertScope($runtime, $effect);
            $request = $this->request($effect);
        } catch (\Throwable $exception) {
            if ($exception instanceof \InvalidArgumentException
                && $exception->getMessage() === 'paper_canonical_fake_effect_invalid'
            ) {
                throw $exception;
            }

            throw new \InvalidArgumentException('paper_canonical_fake_effect_invalid', 0, $exception);
        }

        $cursor = $runtime->eventCursor();
        if (!$runtime->adapter->setLeverage($effect->plan->symbol, $effect->plan->finalLeverage, 'isolated')) {
            return new PaperFakeDispatchResult(
                new ExecutionResult(
                    $effect->orderIntentIdentity['client_order_id'],
                    null,
                    ExecutionResult::STATUS_ERROR,
                    ['reason' => 'paper_canonical_fake_leverage_rejected'],
                ),
                $this->normalizeSince($runtime, $cursor),
                false,
            );
        }

        $placed = $runtime->adapter->placeOrder($request);
        $idempotentReplay = ($placed->metadata['idempotent_replay'] ?? false) === true;
        $orderMetadata = $placed->metadata !== []
            ? $placed->metadata
            : ($placed->order?->metadata ?? []);
        $raw = [
            'accepted' => $placed->accepted,
            'status' => $placed->status->value,
            'idempotent_replay' => $idempotentReplay,
            'plan_hash' => $effect->plan->planHash,
            'order' => [
                'metadata' => $orderMetadata,
            ],
        ];
        if (!$placed->accepted) {
            $raw['reason'] = is_string($placed->metadata['reason'] ?? null)
                ? $placed->metadata['reason']
                : 'paper_canonical_fake_order_rejected';
        }

        return new PaperFakeDispatchResult(
            new ExecutionResult(
                $placed->clientOrderId,
                $placed->exchangeOrderId,
                $placed->accepted
                    ? ExecutionResult::STATUS_SUBMITTED_PROTECTED
                    : ExecutionResult::STATUS_ERROR,
                $raw,
            ),
            $this->normalizeSince($runtime, $cursor),
            $idempotentReplay,
        );
    }

    private function assertScope(PaperFakeRuntime $runtime, PaperCanonicalPreparedEffect $effect): void
    {
        $effect->assertValid();
        $eligibility = PaperProfileEligibility::from($effect->provenance['paper_eligibility']);
        if (!$runtime->cell->isModern()
            || $runtime->adapter->exchange() !== Exchange::FAKE
            || $runtime->adapter->marketType() !== MarketType::PERPETUAL
            || $runtime->cell->provenance($eligibility) !== $effect->provenance
            || count($effect->plan->targets) !== 1
        ) {
            throw new \InvalidArgumentException('paper_canonical_fake_effect_invalid');
        }
    }

    private function request(PaperCanonicalPreparedEffect $effect): PlaceOrderRequest
    {
        $plan = $effect->plan;
        $side = match ($plan->side) {
            'long' => ExchangeOrderSide::BUY,
            'short' => ExchangeOrderSide::SELL,
            default => throw new \InvalidArgumentException('paper_canonical_fake_effect_invalid'),
        };
        $positionSide = match ($plan->side) {
            'long' => ExchangePositionSide::LONG,
            'short' => ExchangePositionSide::SHORT,
            default => throw new \InvalidArgumentException('paper_canonical_fake_effect_invalid'),
        };
        $orderType = match ($plan->orderType) {
            'limit' => ExchangeOrderType::LIMIT,
            'market' => ExchangeOrderType::MARKET,
            default => throw new \InvalidArgumentException('paper_canonical_fake_effect_invalid'),
        };
        $target = $plan->targets[0];
        $metadata = array_replace(
            $effect->lineage->redacted(),
            $effect->provenance,
            [
                'order_intent_id' => $effect->orderIntentIdentity['order_intent_id'],
                'client_order_id' => $effect->orderIntentIdentity['client_order_id'],
                'plan_hash' => $plan->planHash,
                'target_id' => $target->id,
                'canonical_side' => strtoupper($plan->side),
                'canonical_dispatch_source' => 'paper_canonical_fake_dispatcher',
            ],
        );
        PaperMarketEventRedactor::assertSafe($metadata);

        $price = $orderType === ExchangeOrderType::LIMIT ? $plan->entryPrice : null;

        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $plan->symbol,
            side: $side,
            positionSide: $positionSide,
            orderType: $orderType,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: $plan->quantity,
            price: $price,
            stopPrice: null,
            reduceOnly: false,
            postOnly: $orderType === ExchangeOrderType::LIMIT && $plan->entryLiquidityRole === 'maker',
            leverage: $plan->finalLeverage,
            marginMode: 'isolated',
            clientOrderId: $effect->orderIntentIdentity['client_order_id'],
            attachedStopLossPrice: $plan->stopPrice,
            attachedTakeProfitPrice: $target->price,
            metadata: $metadata,
            quantityDecimal: $this->decimal($plan->quantity),
            priceDecimal: $price !== null ? $this->decimal($price) : null,
            stopPriceDecimal: null,
            attachedStopLossPriceDecimal: $this->decimal($plan->stopPrice),
            attachedTakeProfitPriceDecimal: $this->decimal($target->price),
        );
    }

    private function decimal(float $value): string
    {
        return (string) CanonicalOrderPlanDecimal::fromFloat(
            $value,
            'paper_canonical_fake_effect_invalid',
        )->stripTrailingZeros();
    }

    /** @return list<ExchangeEventInterface> */
    private function normalizeSince(PaperFakeRuntime $runtime, int $cursor): array
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
