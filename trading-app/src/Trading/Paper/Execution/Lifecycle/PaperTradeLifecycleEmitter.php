<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Lifecycle;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\PositionSide;
use App\Entity\Position;
use App\Entity\TradeLineage;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Event\AbstractExchangePositionEvent;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Event\ExchangePositionClosed;
use App\Exchange\Event\ExchangePositionOpened;
use App\Logging\TradeLifecycleEventType;
use App\Logging\TradeLifecycleLogger;
use App\Provider\Context\ExchangeContext;
use App\Repository\PositionRepository;
use App\Repository\TradeLifecycleEventRepository;
use App\TradeEntry\Types\Side;
use App\Trading\Dto\PositionDto;
use App\Trading\Dto\PositionHistoryEntryDto;
use App\Trading\Event\PositionClosedEvent;
use App\Trading\Event\PositionOpenedEvent;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Listener\TradeLifecycleLoggerListener;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Pnl\FillQuantityAggregationProviderInterface;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;
use Brick\Math\BigDecimal;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes, for a Paper trade, the trade_lifecycle_event rows live trading writes (#132 j, J1b):
 *
 *  - order_submitted, as TradeEntryService does: TradeLifecycleLogger::logOrderSubmitted() with
 *    the execution identity of the order intent (order_id = the accepted Fake order);
 *  - position_opened and position_closed, through TradeLifecycleLoggerListener itself, called
 *    directly on the Paper instance of the listener: no event is dispatched, so no other
 *    listener (symbol lock release, trading log) runs, and its MFE/MAE klines come from the
 *    verified dataset of the replay (PaperLifecycleMainProvider), never from a venue.
 *
 * The Paper instances of the logger (PaperReplayClock), the listener and the projection store
 * are the "paper.execution.*" services of config/services.yaml. Without them this sink is inert
 * and the coordinator keeps its own projection store, as before #132 j.
 *
 * It runs inside the transaction that projects the batch and acknowledges its effect: a crash
 * rolls its rows back with the projection, and the resumed replay writes them once. A row that
 * already exists (same type, order or position, trade) is not written again.
 */
#[AsAlias(PaperTradeLifecycleSinkInterface::class)]
final readonly class PaperTradeLifecycleEmitter implements PaperTradeLifecycleSinkInterface
{
    public function __construct(
        private TradeLifecycleEventRepository $lifecycleEvents,
        private TradeLineageManager $lineages,
        private PositionRepository $positions,
        private FillQuantityAggregationProviderInterface $fills,
        #[Autowire(service: 'paper.execution.exchange_projection')]
        private ?ExchangeLocalProjectionStoreInterface $projection = null,
        #[Autowire(service: 'paper.execution.lifecycle_listener')]
        private ?TradeLifecycleLoggerListener $listener = null,
        #[Autowire(service: 'paper.execution.lifecycle_logger')]
        private ?TradeLifecycleLogger $logger = null,
    ) {
    }

    public function projection(): ?ExchangeLocalProjectionStoreInterface
    {
        return $this->enabled() ? $this->projection : null;
    }

    public function afterProjection(PaperExecutionCell $cell, array $events, ?PaperLifecycleSubmission $submission): void
    {
        if (!$this->enabled()) {
            return;
        }
        if ($submission !== null) {
            $this->orderSubmitted($submission);
        }
        foreach ($events as $event) {
            if ($event instanceof ExchangePositionOpened) {
                $this->positionOpened($event);
            } elseif ($event instanceof ExchangePositionClosed) {
                $this->positionClosed($event);
            }
        }
    }

    private function enabled(): bool
    {
        return $this->projection !== null && $this->listener !== null && $this->logger !== null;
    }

    private function orderSubmitted(PaperLifecycleSubmission $submission): void
    {
        $execution = $submission->execution;
        $orderId = $execution->exchangeOrderId;
        if ($orderId === null || trim($orderId) === '') {
            return;
        }
        $context = new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL);
        $lineage = $this->lineage($context, $execution->clientOrderId, $orderId);
        if ($this->exists(TradeLifecycleEventType::ORDER_SUBMITTED, ['orderId' => $orderId], $context)) {
            return;
        }

        $effect = $submission->canonical;
        if ($effect !== null) {
            $plan = $effect->plan;
            $identity = $lineage->getOrderIntent()?->requireExecutionLineageContext()
                ?? throw new \LogicException('paper_lifecycle_order_intent_missing');
            $target = $plan->targets[0] ?? null;
            $this->logger()->logOrderSubmitted(
                symbol: $plan->symbol,
                orderId: $orderId,
                clientOrderId: $execution->clientOrderId,
                side: strtolower($plan->side) === 'short' ? 'SELL' : 'BUY',
                qty: self::decimal($plan->quantity),
                price: $plan->orderType === 'market' ? null : self::decimal($plan->entryPrice),
                runId: $lineage->getRunId(),
                exchange: $context->exchange->value,
                accountId: null,
                extra: array_merge($this->lineages->lifecycleExtra($lineage), [
                    'decision_key' => $effect->decisionKey,
                    'order_type' => $plan->orderType,
                    'stop' => $plan->stopPrice,
                    'take_profit' => $target?->price,
                    'leverage' => $plan->finalLeverage,
                    'trade_entry_mode' => $plan->modeId,
                    'timeframe' => $effect->executionTimeframe,
                    // 1R of the canonical plan: its net loss at the stop, costs included, the
                    // denominator of its own net R (targets[].netR). The risk budget only bounds
                    // the size: caps can keep a position far below it.
                    'risk_usdt' => $plan->totalStopLoss,
                    'risk_budget_usdt' => $plan->riskBudgetQuote,
                    'notional_usdt' => $plan->positionNotional,
                    'initial_stop_price' => $plan->stopPrice,
                    'stop_distance_pct' => $plan->entryPrice > 0.0 ? abs($plan->entryPrice - $plan->stopPrice) / $plan->entryPrice : null,
                    'expected_r_multiple' => $target?->riskMultiple,
                    'source' => 'paper_canonical_fake_dispatcher',
                ]),
                timeframe: $effect->executionTimeframe,
                configProfile: $plan->modeId,
                marketType: $context->marketType->value,
                lineageContext: $identity->withExecution($orderId, null, $lineage->getInternalTradeId()),
            );

            return;
        }

        $decision = $submission->legacy ?? throw new \LogicException('paper_lifecycle_submission_invalid');
        $prepared = $decision->prepared;
        $plan = $prepared->plan ?? throw new \LogicException('paper_lifecycle_submission_invalid');
        $this->logger()->logOrderSubmitted(
            symbol: $plan->symbol,
            orderId: $orderId,
            clientOrderId: $execution->clientOrderId,
            side: $plan->side === Side::Long ? 'BUY' : 'SELL',
            qty: (string) $plan->size,
            price: $plan->orderType === 'market' ? null : self::decimal($plan->entry),
            runId: $lineage->getRunId(),
            exchange: $context->exchange->value,
            accountId: null,
            extra: array_merge($prepared->lifecycle->toArray(), $this->lineages->lifecycleExtra($lineage), [
                'decision_key' => $prepared->decisionKey,
                'order_type' => $plan->orderType,
                'order_mode' => $plan->orderMode,
                'stop' => $plan->stop,
                'take_profit' => $plan->takeProfit,
                'leverage' => $plan->leverage,
                'trade_entry_mode' => $prepared->mode,
                'source' => 'paper_fake_dispatcher',
            ]),
            timeframe: $prepared->executionTimeframe,
            configProfile: $prepared->mode,
            marketType: $context->marketType->value,
        );
    }

    private function positionOpened(ExchangePositionOpened $event): void
    {
        $context = new ExchangeContext($event->exchange(), $event->marketType());
        $position = $this->projectedPosition($event, $context);
        $positionId = $position->getCanonicalExchangePositionId()
            ?? throw new \LogicException('paper_lifecycle_position_id_missing');
        $opening = $position->getOpeningOrder();
        $lineage = $this->lineage($context, $opening?->getClientOrderId(), $opening?->getOrderId());
        if ($this->exists(TradeLifecycleEventType::POSITION_OPENED, [
            'positionId' => $positionId,
            'internalTradeId' => $lineage->getInternalTradeId(),
        ], $context)) {
            return;
        }
        $entryPrice = BigDecimal::of($position->getAvgEntryPrice() ?? throw new \LogicException('paper_lifecycle_entry_price_missing'));

        $this->listener()->onPositionOpened(new PositionOpenedEvent(
            position: new PositionDto(
                symbol: $event->symbol(),
                side: self::side($event->side()),
                size: CanonicalOrderPlanDecimal::fromFloat($event->size(), 'paper_lifecycle_decimal_invalid'),
                entryPrice: $entryPrice,
                markPrice: $entryPrice,
                unrealizedPnl: BigDecimal::zero(),
                leverage: BigDecimal::of($position->getLeverage() ?? 1),
                openedAt: $event->occurredAt(),
                raw: self::raw($positionId, $context, $event->payload()),
                exchangePositionId: $positionId,
                exchangeOrderId: $opening?->getOrderId(),
                clientOrderId: $opening?->getClientOrderId(),
                exchangeFillId: $position->getOpeningFill()?->getTradeId(),
            ),
            runId: null,
            exchange: $context->exchange->value,
            accountId: null,
            extra: [
                'market_type' => $context->marketType->value,
                'internal_trade_id' => $lineage->getInternalTradeId(),
            ],
        ));
    }

    private function positionClosed(ExchangePositionClosed $event): void
    {
        $context = new ExchangeContext($event->exchange(), $event->marketType());
        $payload = $event->payload();
        $position = $this->projectedPosition($event, $context);
        $positionId = $position->getCanonicalExchangePositionId()
            ?? throw new \LogicException('paper_lifecycle_position_id_missing');
        $lineage = $this->lineage(
            $context,
            self::text($payload['opening_client_order_id'] ?? null) ?? $position->getOpeningOrder()?->getClientOrderId(),
            self::text($payload['opening_order_id'] ?? null) ?? $position->getOpeningOrder()?->getOrderId(),
        );
        $internalTradeId = $lineage->getInternalTradeId();
        if ($this->exists(TradeLifecycleEventType::POSITION_CLOSED, [
            'positionId' => $positionId,
            'internalTradeId' => $internalTradeId,
        ], $context)) {
            return;
        }
        $fills = $this->fills->aggregateByTradeVenue($internalTradeId, $context->exchange->value, $context->marketType->value);
        if ($fills->entryVwap === null || $fills->exitVwap === null || $fills->entryQty === null
            || !$fills->entryFirstFillAt instanceof \DateTimeImmutable
        ) {
            throw new \LogicException('paper_lifecycle_fill_ledger_incomplete');
        }

        $this->listener()->onPositionClosed(new PositionClosedEvent(
            positionHistory: new PositionHistoryEntryDto(
                symbol: $event->symbol(),
                side: self::side($event->side()),
                size: CanonicalOrderPlanDecimal::fromFloat($fills->entryQty, 'paper_lifecycle_decimal_invalid'),
                entryPrice: CanonicalOrderPlanDecimal::fromFloat($fills->entryVwap, 'paper_lifecycle_decimal_invalid'),
                exitPrice: CanonicalOrderPlanDecimal::fromFloat($fills->exitVwap, 'paper_lifecycle_decimal_invalid'),
                realizedPnl: BigDecimal::of(self::text($payload['gross_realized_pnl_usdt_decimal'] ?? null)
                    ?? throw new \LogicException('paper_lifecycle_realized_pnl_missing')),
                fees: self::fees($payload),
                openedAt: $fills->entryFirstFillAt,
                closedAt: $event->occurredAt(),
                raw: self::raw($positionId, $context, $payload),
                exchangePositionId: $positionId,
                exchangeOrderId: self::text($payload['opening_order_id'] ?? null),
                clientOrderId: self::text($payload['opening_client_order_id'] ?? null),
                exchangeFillId: self::text($payload['opening_fill_id'] ?? null),
            ),
            runId: null,
            exchange: $context->exchange->value,
            accountId: null,
            reasonCode: null,
            extra: [
                'market_type' => $context->marketType->value,
                'internal_trade_id' => $internalTradeId,
            ],
        ));
    }

    private function projectedPosition(AbstractExchangePositionEvent $event, ExchangeContext $context): Position
    {
        $positionId = $event->canonicalEvidence()->exchangePositionId;
        $position = $positionId !== null
            ? $this->positions->findOneByCanonicalExchangePositionId($positionId, $context)
            : $this->positions->findOneBySymbolSide($event->symbol(), $event->side()->value, $context);

        return $position ?? throw new \LogicException('paper_lifecycle_position_missing');
    }

    private function lineage(ExchangeContext $context, ?string $clientOrderId, ?string $exchangeOrderId): TradeLineage
    {
        return $this->lineages->resolve($context, clientOrderId: $clientOrderId, exchangeOrderId: $exchangeOrderId)
            ?? throw new \LogicException('paper_lifecycle_lineage_missing');
    }

    /** @param array<string, string> $identity */
    private function exists(string $eventType, array $identity, ExchangeContext $context): bool
    {
        return $this->lifecycleEvents->findRecentBy($identity + [
            'eventType' => $eventType,
            'exchange' => $context->exchange->value,
            'marketType' => $context->marketType->value,
        ], 1) !== [];
    }

    private function listener(): TradeLifecycleLoggerListener
    {
        return $this->listener ?? throw new \LogicException('paper_lifecycle_unavailable');
    }

    private function logger(): TradeLifecycleLogger
    {
        return $this->logger ?? throw new \LogicException('paper_lifecycle_unavailable');
    }

    private static function side(ExchangePositionSide $side): PositionSide
    {
        return $side === ExchangePositionSide::SHORT ? PositionSide::SHORT : PositionSide::LONG;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function raw(string $positionId, ExchangeContext $context, array $payload): array
    {
        return [
            'position_id' => $positionId,
            'exchange' => $context->exchange->value,
            'market_type' => $context->marketType->value,
            'source' => 'paper_fake_projection',
            'payload' => $payload,
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function fees(array $payload): ?BigDecimal
    {
        $entry = $payload['entry_fee_usdt'] ?? null;
        $exit = $payload['exit_fee_usdt'] ?? null;
        if ((!\is_float($entry) && !\is_int($entry)) || (!\is_float($exit) && !\is_int($exit))) {
            return null;
        }

        return CanonicalOrderPlanDecimal::fromFloat((float) $entry, 'paper_lifecycle_decimal_invalid')
            ->plus(CanonicalOrderPlanDecimal::fromFloat((float) $exit, 'paper_lifecycle_decimal_invalid'));
    }

    private static function decimal(float $value): string
    {
        return (string) CanonicalOrderPlanDecimal::fromFloat($value, 'paper_lifecycle_decimal_invalid');
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
