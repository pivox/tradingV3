<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
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
use Psr\Clock\ClockInterface;

final readonly class PaperCanonicalFakeEffectDispatcher
{
    public function __construct(
        private FakeExchangeEventNormalizer $normalizer,
        private ClockInterface $clock,
    ) {
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
        $existing = $this->existingOrder($runtime, $effect);
        if ($existing instanceof ExchangeOrderDto) {
            return $this->replay($effect, $existing);
        }
        $inactiveReason = $this->inactiveReason($effect);
        if ($inactiveReason !== null) {
            return $this->result(
                $effect->orderIntentIdentity['client_order_id'],
                null,
                false,
                ExchangeOrderStatus::REJECTED->value,
                ['reason' => $inactiveReason, 'plan_hash' => $effect->plan->planHash],
                false,
                $effect->plan->planHash,
                [],
            );
        }
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
        return $this->result(
            $placed->clientOrderId,
            $placed->exchangeOrderId,
            $placed->accepted,
            $placed->status->value,
            $orderMetadata,
            $idempotentReplay,
            $effect->plan->planHash,
            $this->normalizeSince($runtime, $cursor),
        );
    }

    private function inactiveReason(PaperCanonicalPreparedEffect $effect): ?string
    {
        $now = $this->clock->now();
        if ($effect->plan->createdAt > $now) {
            return 'paper_canonical_fake_plan_not_active';
        }
        $deadline = $effect->plan->expiresAt;
        if ($effect->plan->cancelAfterAt !== null && $effect->plan->cancelAfterAt < $deadline) {
            $deadline = $effect->plan->cancelAfterAt;
        }

        return $now >= $deadline ? 'paper_canonical_fake_plan_expired' : null;
    }

    private function existingOrder(
        PaperFakeRuntime $runtime,
        PaperCanonicalPreparedEffect $effect,
    ): ?ExchangeOrderDto {
        foreach ($runtime->adapter->getOrdersSnapshot($effect->plan->symbol) as $order) {
            if ($order->clientOrderId === $effect->orderIntentIdentity['client_order_id']) {
                return $order;
            }
        }

        return null;
    }

    private function replay(
        PaperCanonicalPreparedEffect $effect,
        ExchangeOrderDto $order,
    ): PaperFakeDispatchResult {
        $planHash = $order->metadata['plan_hash'] ?? null;
        $orderIntentId = $order->metadata['order_intent_id'] ?? null;
        if (!is_string($planHash)
            || !hash_equals($effect->plan->planHash, $planHash)
            || $orderIntentId !== $effect->orderIntentIdentity['order_intent_id']
            || ($order->metadata['canonical_dispatch_source'] ?? null) !== 'paper_canonical_fake_dispatcher'
        ) {
            return $this->result(
                $effect->orderIntentIdentity['client_order_id'],
                $order->exchangeOrderId,
                false,
                ExchangeOrderStatus::REJECTED->value,
                array_replace($order->metadata, ['reason' => 'duplicate_client_order_id_intent_mismatch']),
                false,
                $effect->plan->planHash,
                [],
            );
        }

        return $this->result(
            $effect->orderIntentIdentity['client_order_id'],
            $order->exchangeOrderId,
            !in_array($order->status, [ExchangeOrderStatus::REJECTED, ExchangeOrderStatus::UNKNOWN], true),
            $order->status->value,
            array_replace($order->metadata, ['idempotent_replay' => true]),
            true,
            $effect->plan->planHash,
            [],
        );
    }

    /**
     * @param array<string, mixed>         $metadata
     * @param list<ExchangeEventInterface> $events
     */
    private function result(
        string $clientOrderId,
        ?string $exchangeOrderId,
        bool $accepted,
        string $exchangeStatus,
        array $metadata,
        bool $idempotentReplay,
        string $planHash,
        array $events,
    ): PaperFakeDispatchResult {
        $status = $this->executionStatus($accepted, $metadata);
        $raw = [
            'accepted' => $accepted,
            'status' => $exchangeStatus,
            'idempotent_replay' => $idempotentReplay,
            'plan_hash' => $planHash,
            'order' => ['metadata' => $metadata],
        ];
        if ($status !== ExecutionResult::STATUS_SUBMITTED_PROTECTED) {
            $raw['reason'] = $this->failureReason($status, $metadata);
        }

        return new PaperFakeDispatchResult(
            new ExecutionResult($clientOrderId, $exchangeOrderId, $status, $raw),
            $events,
            $idempotentReplay,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function executionStatus(bool $accepted, array $metadata): string
    {
        if (($metadata['protection_status'] ?? null) === 'rejected') {
            return ($metadata['compensation_status'] ?? null) === 'completed'
                ? ExecutionResult::STATUS_FAILED_UNPROTECTED_CLOSED
                : ExecutionResult::STATUS_CRITICAL_UNPROTECTED_POSITION;
        }

        return $accepted ? ExecutionResult::STATUS_SUBMITTED_PROTECTED : ExecutionResult::STATUS_ERROR;
    }

    /** @param array<string, mixed> $metadata */
    private function failureReason(string $status, array $metadata): string
    {
        foreach (['protection_reject_reason', 'reason'] as $key) {
            if (is_string($metadata[$key] ?? null) && $metadata[$key] !== '') {
                return $metadata[$key];
            }
        }

        return $status === ExecutionResult::STATUS_CRITICAL_UNPROTECTED_POSITION
            ? 'paper_canonical_fake_unprotected_position'
            : 'paper_canonical_fake_order_rejected';
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
                'canonical_plan_expires_at' => $this->time($plan->expiresAt),
                'canonical_cancel_after_at' => $plan->cancelAfterAt !== null
                    ? $this->time($plan->cancelAfterAt)
                    : null,
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

    private function time(\DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d\\TH:i:s.uP');
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
