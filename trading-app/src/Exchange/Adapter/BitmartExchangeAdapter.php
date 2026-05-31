<?php

declare(strict_types=1);

namespace App\Exchange\Adapter;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderStatus as ProviderOrderStatus;
use App\Common\Enum\OrderType;
use App\Common\Enum\PositionSide as ProviderPositionSide;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\PositionDto;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Contract\Provider\ExchangeProviderRegistryInterface;
use App\Contract\Provider\OrderProviderDecoratorInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\CancelOrderResult;
use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\ExchangeReconciliationResult;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\PlaceOrderResult;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Provider\Context\ExchangeContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exchange_adapter')]
final class BitmartExchangeAdapter implements ExchangeAdapterInterface
{
    private readonly ExchangeContext $context;

    public function __construct(
        private readonly ExchangeProviderRegistryInterface $providerRegistry,
        private readonly ClockInterface $clock,
    ) {
        $this->context = new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL);
    }

    public function exchange(): Exchange
    {
        return $this->context->exchange;
    }

    public function marketType(): MarketType
    {
        return $this->context->marketType;
    }

    public function capabilities(): ExchangeCapabilities
    {
        return new ExchangeCapabilities(
            supportsTestnet: false,
            supportsWebSocketPrivate: true,
            supportsClientOrderId: true,
            supportsCancelByClientOrderId: false,
            supportsPostOnly: true,
            supportsIoc: true,
            supportsReduceOnly: true,
            supportsAttachedStopLossOnEntry: true,
            supportsAttachedTakeProfitOnEntry: true,
            supportsTriggerOrders: false,
            supportsModifyOrder: false,
            requiresSeparateLeverageSubmit: true,
            supportsPerSymbolLeverage: true,
        );
    }

    public function getBalances(): array
    {
        $account = $this->bundle()->account()->getAccountInfo();
        if ($account !== null) {
            return [
                new ExchangeBalanceDto(
                    exchange: $this->exchange(),
                    marketType: $this->marketType(),
                    currency: $account->currency,
                    available: $account->availableBalance->toFloat(),
                    total: $account->equity->toFloat(),
                    equity: $account->equity->toFloat(),
                    unrealizedPnl: $account->unrealized->toFloat(),
                    metadata: $account->metadata,
                ),
            ];
        }

        return [
            new ExchangeBalanceDto(
                exchange: $this->exchange(),
                marketType: $this->marketType(),
                currency: 'USDT',
                available: $this->bundle()->account()->getAccountBalance('USDT'),
            ),
        ];
    }

    public function getOpenPositions(?string $symbol = null): array
    {
        return array_values(array_map(
            fn (PositionDto $position): ExchangePositionDto => $this->mapPosition($position),
            $this->bundle()->account()->getOpenPositions($symbol),
        ));
    }

    public function getOpenOrders(?string $symbol = null): array
    {
        $orders = array_values(array_map(
            fn (OrderDto $order): ExchangeOrderDto => $this->mapOrder($order),
            $this->bundle()->order()->getOpenOrders($symbol),
        ));

        foreach ($this->getPlanOrders($symbol) as $planOrder) {
            $mapped = $this->mapPlanOrder($planOrder);
            if ($mapped instanceof ExchangeOrderDto) {
                $orders[] = $mapped;
            }
        }

        return $orders;
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        $this->assertRequestContext($request->exchange, $request->marketType);
        $this->assertRequestIntent($request);
        $providerOrderType = $this->mapOrderTypeToProvider($request->orderType);
        $legacyOptions = $this->buildLegacyOrderOptions($request);

        $order = $this->bundle()->order()->placeOrder(
            symbol: $request->symbol,
            side: $this->mapOrderSideToProvider($request->side),
            type: $providerOrderType,
            quantity: $request->quantity,
            price: $request->price,
            stopPrice: $request->stopPrice,
            options: $legacyOptions,
        );

        if (!$order instanceof OrderDto) {
            return new PlaceOrderResult(
                accepted: false,
                symbol: $request->symbol,
                clientOrderId: $request->clientOrderId,
                exchangeOrderId: null,
                status: ExchangeOrderStatus::REJECTED,
                submittedAt: $this->clock->now(),
                metadata: ['reason' => 'provider_returned_null'],
            );
        }

        $mapped = $this->mapOrder($order, $this->buildSubmittedOrderMetadata($request, $legacyOptions));

        return new PlaceOrderResult(
            accepted: true,
            symbol: $request->symbol,
            clientOrderId: $request->clientOrderId,
            exchangeOrderId: $order->orderId,
            status: $mapped->status,
            submittedAt: $this->clock->now(),
            order: $mapped,
        );
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        $this->assertRequestContext($request->exchange, $request->marketType);

        $cancelled = false;
        if ($request->exchangeOrderId !== null && trim($request->exchangeOrderId) !== '') {
            $cancelled = $this->bundle()->order()->cancelOrder($request->symbol, $request->exchangeOrderId);
        }

        return new CancelOrderResult(
            cancelled: $cancelled,
            symbol: $request->symbol,
            exchangeOrderId: $request->exchangeOrderId,
            clientOrderId: $request->clientOrderId,
            status: $cancelled ? ExchangeOrderStatus::CANCELLED : ExchangeOrderStatus::UNKNOWN,
            metadata: $this->cancelFailureMetadata($request, $cancelled),
        );
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        $order = $this->bundle()->order()->getOrder($symbol, $exchangeOrderId);

        return $order instanceof OrderDto ? $this->mapOrder($order) : null;
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return $this->bundle()->order()->getOrderBookTop($symbol);
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        return $this->bundle()->order()->submitLeverage($symbol, $leverage, $marginMode);
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        $now = $this->clock->now();

        return new ExchangeReconciliationResult(
            exchange: $this->exchange(),
            marketType: $this->marketType(),
            symbol: $symbol,
            startedAt: $now,
            completedAt: $now,
            metadata: ['reason' => 'legacy_bitmart_reconciliation_not_wired_yet'],
        );
    }

    private function bundle(): \App\Provider\Registry\ExchangeProviderBundle
    {
        return $this->providerRegistry->get($this->context);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getPlanOrders(?string $symbol): array
    {
        $orderProvider = $this->unwrapOrderProvider($this->bundle()->order());
        if (!method_exists($orderProvider, 'getPlanOrders')) {
            return [];
        }

        try {
            /** @var array<int,array<string,mixed>> $planOrders */
            $planOrders = $orderProvider->getPlanOrders($symbol);
            return $planOrders;
        } catch (\Throwable) {
            return [];
        }
    }

    private function unwrapOrderProvider(OrderProviderInterface $provider): OrderProviderInterface
    {
        while ($provider instanceof OrderProviderDecoratorInterface) {
            $provider = $provider->innerOrderProvider();
        }

        return $provider;
    }

    /**
     * @param array<string,mixed> $planOrder
     */
    private function mapPlanOrder(array $planOrder): ?ExchangeOrderDto
    {
        $orderId = $planOrder['plan_order_id'] ?? $planOrder['order_id'] ?? null;
        $triggerPrice = $planOrder['trigger_price']
            ?? $planOrder['preset_stop_loss_price']
            ?? $planOrder['preset_take_profit_price']
            ?? null;
        if ($orderId === null || $triggerPrice === null || $triggerPrice === '') {
            return null;
        }

        [$side, $positionSide] = $this->mapOrderSideFromMetadata(
            new OrderDto(
                orderId: (string) $orderId,
                symbol: (string) ($planOrder['symbol'] ?? ''),
                side: OrderSide::BUY,
                type: OrderType::STOP,
                status: ProviderOrderStatus::PENDING,
                quantity: \Brick\Math\BigDecimal::of('0'),
                price: null,
                stopPrice: null,
                filledQuantity: \Brick\Math\BigDecimal::of('0'),
                remainingQuantity: \Brick\Math\BigDecimal::of('0'),
                averagePrice: null,
                createdAt: $this->clock->now(),
            ),
            $planOrder,
        );
        $quantity = $this->floatPlanOrderValue($planOrder, ['size', 'quantity', 'vol', 'volume']);

        return new ExchangeOrderDto(
            exchange: $this->exchange(),
            marketType: $this->marketType(),
            symbol: strtoupper((string) ($planOrder['symbol'] ?? '')),
            exchangeOrderId: (string) $orderId,
            clientOrderId: isset($planOrder['client_order_id']) ? (string) $planOrder['client_order_id'] : null,
            side: $side,
            positionSide: $positionSide,
            orderType: ExchangeOrderType::TRIGGER,
            status: $this->mapPlanOrderStatus($planOrder['state'] ?? $planOrder['status'] ?? null),
            quantity: $quantity,
            filledQuantity: 0.0,
            remainingQuantity: $quantity,
            price: null,
            averagePrice: null,
            stopPrice: (float) $triggerPrice,
            reduceOnly: true,
            postOnly: false,
            timeInForce: ExchangeTimeInForce::GTC,
            createdAt: $this->clock->now(),
            metadata: ['source' => 'bitmart_plan_order'] + $planOrder,
        );
    }

    /**
     * @param array<string,mixed> $planOrder
     * @param string[] $keys
     */
    private function floatPlanOrderValue(array $planOrder, array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($planOrder[$key]) && is_numeric($planOrder[$key])) {
                return (float) $planOrder[$key];
            }
        }

        return 0.0;
    }

    private function mapPlanOrderStatus(mixed $state): ExchangeOrderStatus
    {
        return match ((int) $state) {
            2 => ExchangeOrderStatus::PARTIALLY_FILLED,
            3 => ExchangeOrderStatus::FILLED,
            4 => ExchangeOrderStatus::CANCELLED,
            5 => ExchangeOrderStatus::REJECTED,
            default => ExchangeOrderStatus::PENDING,
        };
    }

    /**
     * @return array<string,string>
     */
    private function cancelFailureMetadata(CancelOrderRequest $request, bool $cancelled): array
    {
        if ($cancelled) {
            return [];
        }

        if ($request->exchangeOrderId !== null && trim($request->exchangeOrderId) !== '') {
            return ['reason' => 'exchange_order_cancel_failed'];
        }

        if ($request->clientOrderId !== null && trim($request->clientOrderId) !== '') {
            return ['reason' => 'cancel_by_client_order_id_not_supported'];
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildLegacyOrderOptions(PlaceOrderRequest $request): array
    {
        $options = [
            'client_order_id' => $request->clientOrderId,
            'side' => $this->mapPositionIntentToLegacySide($request),
            'open_type' => $request->marginMode,
        ];

        if ($request->timeInForce === ExchangeTimeInForce::IOC) {
            $options['mode'] = 3;
        } elseif ($request->timeInForce === ExchangeTimeInForce::FOK) {
            $options['mode'] = 2;
        } elseif ($request->postOnly) {
            $options['mode'] = 4;
        }

        if ($request->leverage !== null) {
            $options['leverage'] = $request->leverage;
        }
        if ($request->attachedStopLossPrice !== null) {
            $options['preset_stop_loss_price'] = (string) $request->attachedStopLossPrice;
            $options['preset_stop_loss_price_type'] = 1;
        }
        if ($request->attachedTakeProfitPrice !== null) {
            $options['preset_take_profit_price'] = (string) $request->attachedTakeProfitPrice;
            $options['preset_take_profit_price_type'] = 1;
        }
        foreach (['decision_key', 'order_intent_id'] as $metadataKey) {
            if (isset($request->metadata[$metadataKey]) && $request->metadata[$metadataKey] !== null && $request->metadata[$metadataKey] !== '') {
                $options[$metadataKey] = $request->metadata[$metadataKey];
            }
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $legacyOptions
     * @return array<string,mixed>
     */
    private function buildSubmittedOrderMetadata(PlaceOrderRequest $request, array $legacyOptions): array
    {
        return $legacyOptions + [
            'mode' => $request->timeInForce === ExchangeTimeInForce::GTC && !$request->postOnly ? 1 : null,
            'post_only' => $request->postOnly,
            'reduce_only' => $request->reduceOnly,
        ];
    }

    private function mapPositionIntentToLegacySide(PlaceOrderRequest $request): int
    {
        if ($request->reduceOnly && $request->positionSide === ExchangePositionSide::LONG) {
            return 3;
        }
        if ($request->reduceOnly && $request->positionSide === ExchangePositionSide::SHORT) {
            return 2;
        }

        return $request->positionSide === ExchangePositionSide::LONG ? 1 : 4;
    }

    /**
     * @param array<string,mixed> $submittedMetadata
     */
    private function mapOrder(OrderDto $order, array $submittedMetadata = []): ExchangeOrderDto
    {
        $metadata = array_replace($submittedMetadata, $order->metadata);
        [$side, $positionSide] = $this->mapOrderSideFromMetadata($order, $metadata);
        $bitmartSide = $this->intMetadata($metadata, 'side');
        $mode = $this->intMetadata($metadata, 'mode');

        return new ExchangeOrderDto(
            exchange: $this->exchange(),
            marketType: $this->marketType(),
            symbol: $order->symbol,
            exchangeOrderId: $order->orderId,
            clientOrderId: $this->stringMetadata($metadata, 'client_order_id'),
            side: $side,
            positionSide: $positionSide,
            orderType: $this->mapProviderOrderType($order->type),
            status: $this->mapProviderOrderStatus($order->status),
            quantity: $order->quantity->toFloat(),
            filledQuantity: $order->filledQuantity->toFloat(),
            remainingQuantity: $order->remainingQuantity->toFloat(),
            price: $order->price?->toFloat(),
            averagePrice: $order->averagePrice?->toFloat(),
            stopPrice: $order->stopPrice?->toFloat(),
            reduceOnly: \in_array($bitmartSide, [2, 3], true) || $this->boolMetadata($metadata, 'reduce_only'),
            postOnly: $mode === 4 || $this->boolMetadata($metadata, 'post_only'),
            timeInForce: $this->mapModeToTimeInForce($mode),
            createdAt: $order->createdAt,
            updatedAt: $order->updatedAt,
            metadata: $metadata,
        );
    }

    private function mapPosition(PositionDto $position): ExchangePositionDto
    {
        return new ExchangePositionDto(
            exchange: $this->exchange(),
            marketType: $this->marketType(),
            symbol: $position->symbol,
            side: $position->side === ProviderPositionSide::SHORT
                ? ExchangePositionSide::SHORT
                : ExchangePositionSide::LONG,
            size: $position->size->toFloat(),
            entryPrice: $position->entryPrice->toFloat(),
            markPrice: $position->markPrice->toFloat(),
            unrealizedPnl: $position->unrealizedPnl->toFloat(),
            realizedPnl: $position->realizedPnl->toFloat(),
            margin: $position->margin->toFloat(),
            leverage: $position->leverage->toFloat(),
            openedAt: $position->openedAt,
            updatedAt: $position->closedAt,
            metadata: $position->metadata,
        );
    }

    private function mapOrderSideToProvider(ExchangeOrderSide $side): OrderSide
    {
        return $side === ExchangeOrderSide::SELL ? OrderSide::SELL : OrderSide::BUY;
    }

    private function mapOrderTypeToProvider(ExchangeOrderType $type): OrderType
    {
        return match ($type) {
            ExchangeOrderType::MARKET => OrderType::MARKET,
            ExchangeOrderType::STOP_LOSS,
            ExchangeOrderType::TAKE_PROFIT,
            ExchangeOrderType::TRIGGER => throw new \InvalidArgumentException(
                'Bitmart adapter does not support standalone trigger orders through placeOrder',
            ),
            ExchangeOrderType::LIMIT => OrderType::LIMIT,
        };
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array{0: ExchangeOrderSide, 1: ?ExchangePositionSide}
     */
    private function mapOrderSideFromMetadata(OrderDto $order, array $metadata): array
    {
        return match ($this->intMetadata($metadata, 'side')) {
            1 => [ExchangeOrderSide::BUY, ExchangePositionSide::LONG],
            2 => [ExchangeOrderSide::BUY, ExchangePositionSide::SHORT],
            3 => [ExchangeOrderSide::SELL, ExchangePositionSide::LONG],
            4 => [ExchangeOrderSide::SELL, ExchangePositionSide::SHORT],
            default => [
                $order->side === OrderSide::SELL ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY,
                null,
            ],
        };
    }

    private function mapModeToTimeInForce(?int $mode): ?ExchangeTimeInForce
    {
        return match ($mode) {
            1 => ExchangeTimeInForce::GTC,
            2 => ExchangeTimeInForce::FOK,
            3 => ExchangeTimeInForce::IOC,
            4 => ExchangeTimeInForce::GTC,
            default => null,
        };
    }

    private function mapProviderOrderType(OrderType $type): ExchangeOrderType
    {
        return match ($type) {
            OrderType::MARKET => ExchangeOrderType::MARKET,
            OrderType::STOP,
            OrderType::STOP_LIMIT => ExchangeOrderType::TRIGGER,
            OrderType::LIMIT => ExchangeOrderType::LIMIT,
        };
    }

    private function mapProviderOrderStatus(ProviderOrderStatus $status): ExchangeOrderStatus
    {
        return match ($status) {
            ProviderOrderStatus::FILLED => ExchangeOrderStatus::FILLED,
            ProviderOrderStatus::PARTIALLY_FILLED => ExchangeOrderStatus::PARTIALLY_FILLED,
            ProviderOrderStatus::CANCELLED => ExchangeOrderStatus::CANCELLED,
            ProviderOrderStatus::REJECTED => ExchangeOrderStatus::REJECTED,
            ProviderOrderStatus::EXPIRED => ExchangeOrderStatus::EXPIRED,
            ProviderOrderStatus::PENDING => ExchangeOrderStatus::PENDING,
        };
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function stringMetadata(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        return \is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function intMetadata(array $metadata, string $key): ?int
    {
        $value = $metadata[$key] ?? null;

        return \is_scalar($value) && is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function boolMetadata(array $metadata, string $key): bool
    {
        $value = $metadata[$key] ?? null;
        if ($value === null) {
            return false;
        }

        return \filter_var($value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    private function assertRequestContext(Exchange $exchange, MarketType $marketType): void
    {
        if ($exchange !== $this->exchange() || $marketType !== $this->marketType()) {
            throw new \InvalidArgumentException(sprintf(
                'Bitmart adapter cannot handle "%s::%s"',
                $exchange->value,
                $marketType->value,
            ));
        }
    }

    private function assertRequestIntent(PlaceOrderRequest $request): void
    {
        if ($request->postOnly && $request->orderType !== ExchangeOrderType::LIMIT) {
            throw new \InvalidArgumentException('postOnly is only supported for limit orders on Bitmart');
        }

        if (
            $request->orderType === ExchangeOrderType::MARKET
            && ($request->attachedStopLossPrice !== null || $request->attachedTakeProfitPrice !== null)
        ) {
            throw new \InvalidArgumentException('attached SL/TP on market orders must use the separate Bitmart protection flow');
        }

        if ($request->reduceOnly && ($request->attachedStopLossPrice !== null || $request->attachedTakeProfitPrice !== null)) {
            throw new \InvalidArgumentException('attached SL/TP is only supported for entry orders on Bitmart');
        }

        if ($request->postOnly && \in_array($request->timeInForce, [ExchangeTimeInForce::IOC, ExchangeTimeInForce::FOK], true)) {
            throw new \InvalidArgumentException('postOnly cannot be combined with IOC or FOK on Bitmart');
        }

        $expectedSide = match ([$request->reduceOnly, $request->positionSide]) {
            [false, ExchangePositionSide::LONG] => ExchangeOrderSide::BUY,
            [false, ExchangePositionSide::SHORT] => ExchangeOrderSide::SELL,
            [true, ExchangePositionSide::LONG] => ExchangeOrderSide::SELL,
            [true, ExchangePositionSide::SHORT] => ExchangeOrderSide::BUY,
        };

        if ($request->side !== $expectedSide) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid order side "%s" for %s %s position intent',
                $request->side->value,
                $request->reduceOnly ? 'reduce-only' : 'entry',
                $request->positionSide->value,
            ));
        }
    }
}
