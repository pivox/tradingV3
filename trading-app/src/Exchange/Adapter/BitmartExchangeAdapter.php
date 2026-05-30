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
        return array_values(array_map(
            fn (OrderDto $order): ExchangeOrderDto => $this->mapOrder($order),
            $this->bundle()->order()->getOpenOrders($symbol),
        ));
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        $this->assertRequestContext($request->exchange, $request->marketType);
        $providerOrderType = $this->mapOrderTypeToProvider($request->orderType);

        $order = $this->bundle()->order()->placeOrder(
            symbol: $request->symbol,
            side: $this->mapOrderSideToProvider($request->side),
            type: $providerOrderType,
            quantity: $request->quantity,
            price: $request->price,
            stopPrice: $request->stopPrice,
            options: $this->buildLegacyOrderOptions($request),
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

        $mapped = $this->mapOrder($order);

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
            metadata: $request->clientOrderId !== null && !$cancelled
                ? ['reason' => 'cancel_by_client_order_id_not_supported']
                : [],
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

        return $options;
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

    private function mapOrder(OrderDto $order): ExchangeOrderDto
    {
        [$side, $positionSide] = $this->mapOrderSideFromMetadata($order);
        $bitmartSide = $this->intMetadata($order->metadata, 'side');
        $mode = $this->intMetadata($order->metadata, 'mode');

        return new ExchangeOrderDto(
            exchange: $this->exchange(),
            marketType: $this->marketType(),
            symbol: $order->symbol,
            exchangeOrderId: $order->orderId,
            clientOrderId: $this->stringMetadata($order->metadata, 'client_order_id'),
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
            reduceOnly: \in_array($bitmartSide, [2, 3], true) || $this->boolMetadata($order->metadata, 'reduce_only'),
            postOnly: $mode === 4 || $this->boolMetadata($order->metadata, 'post_only'),
            timeInForce: $this->mapModeToTimeInForce($mode),
            createdAt: $order->createdAt,
            updatedAt: $order->updatedAt,
            metadata: $order->metadata,
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
     * @return array{0: ExchangeOrderSide, 1: ?ExchangePositionSide}
     */
    private function mapOrderSideFromMetadata(OrderDto $order): array
    {
        return match ($this->intMetadata($order->metadata, 'side')) {
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
            2 => ExchangeTimeInForce::FOK,
            3 => ExchangeTimeInForce::IOC,
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
}
