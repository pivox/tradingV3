<?php

declare(strict_types=1);

namespace App\Provider\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderStatus;
use App\Common\Enum\OrderType;
use App\Common\Enum\PositionSide as LegacyPositionSide;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Contract\Provider\OrderProviderInterface;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use Brick\Math\BigDecimal;

/**
 * Legacy order contract backed by the canonical deterministic Fake adapter.
 */
final readonly class FakeOrderProvider implements OrderProviderInterface
{
    public function __construct(private FakeExchangeAdapter $adapter)
    {
    }

    /** @param array<string, mixed> $options */
    public function placeOrder(
        string $symbol,
        OrderSide $side,
        OrderType $type,
        float $quantity,
        ?float $price = null,
        ?float $stopPrice = null,
        array $options = [],
    ): ?OrderDto {
        $this->assertSupportedOptions($options);
        $clientOrderId = $options['client_order_id'] ?? null;
        if (!\is_string($clientOrderId) || trim($clientOrderId) === '') {
            throw new \InvalidArgumentException('Fake legacy orders require a non-blank client_order_id option.');
        }

        $requestedSide = match ($side) {
            OrderSide::BUY => ExchangeOrderSide::BUY,
            OrderSide::SELL => ExchangeOrderSide::SELL,
            OrderSide::UNKNOWN => throw new \InvalidArgumentException('Fake legacy order side UNKNOWN is unsupported.'),
        };
        $legacySide = $this->legacySide($options['side'] ?? null);
        if ($legacySide !== null && $legacySide['side'] !== $requestedSide) {
            throw new \InvalidArgumentException('Legacy side code conflicts with the OrderSide parameter.');
        }
        $canonicalSide = $legacySide['side'] ?? $requestedSide;
        $canonicalType = match ($type) {
            OrderType::LIMIT => ExchangeOrderType::LIMIT,
            OrderType::MARKET => ExchangeOrderType::MARKET,
            OrderType::STOP => ExchangeOrderType::STOP_LOSS,
            OrderType::STOP_LIMIT => throw new \InvalidArgumentException('Fake legacy order type STOP_LIMIT is unsupported.'),
        };
        $positionSide = $this->positionSide($options['position_side'] ?? null, $canonicalSide);
        if ($legacySide !== null) {
            if (array_key_exists('position_side', $options) && $positionSide !== $legacySide['positionSide']) {
                throw new \InvalidArgumentException('Legacy side code conflicts with position_side.');
            }
            $positionSide = $legacySide['positionSide'];
        }
        $timeInForce = $this->timeInForce($options['time_in_force'] ?? null);
        $marginMode = $this->marginMode($options['margin_mode'] ?? $options['open_type'] ?? null);
        $leverage = $this->nullablePositiveInt($options['leverage'] ?? null, 'leverage');
        $reduceOnly = $this->boolOption($options, 'reduce_only');
        $postOnly = $this->boolOption($options, 'post_only');
        if ($legacySide !== null) {
            if (array_key_exists('reduce_only', $options) && $reduceOnly !== $legacySide['reduceOnly']) {
                throw new \InvalidArgumentException('Legacy side code conflicts with reduce_only.');
            }
            $reduceOnly = $legacySide['reduceOnly'];
        }
        $legacyMode = $this->legacyMode($options['mode'] ?? null);
        if ($legacyMode !== null) {
            $expectedTimeInForce = match ($legacyMode) {
                2 => ExchangeTimeInForce::FOK,
                3 => ExchangeTimeInForce::IOC,
                default => ExchangeTimeInForce::GTC,
            };
            if (array_key_exists('time_in_force', $options) && $timeInForce !== $expectedTimeInForce) {
                throw new \InvalidArgumentException('Legacy mode conflicts with time_in_force.');
            }
            $timeInForce = $expectedTimeInForce;
            if ($legacyMode === 4) {
                if (array_key_exists('post_only', $options) && !$postOnly) {
                    throw new \InvalidArgumentException('Legacy maker-only mode requires post_only=true.');
                }
                $postOnly = true;
            }
        }
        $attachedStopLoss = $this->nullablePositiveFloat(
            $options['attached_stop_loss_price'] ?? $options['preset_stop_loss_price'] ?? null,
            'attached_stop_loss_price',
        );
        $attachedTakeProfit = $this->nullablePositiveFloat(
            $options['attached_take_profit_price'] ?? $options['preset_take_profit_price'] ?? null,
            'attached_take_profit_price',
        );

        $result = $this->adapter->placeOrder(new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol,
            side: $canonicalSide,
            positionSide: $positionSide,
            orderType: $canonicalType,
            timeInForce: $timeInForce,
            quantity: $quantity,
            price: $price,
            stopPrice: $stopPrice,
            reduceOnly: $reduceOnly,
            postOnly: $postOnly,
            leverage: $leverage,
            marginMode: $marginMode,
            clientOrderId: $clientOrderId,
            attachedStopLossPrice: $attachedStopLoss,
            attachedTakeProfitPrice: $attachedTakeProfit,
            metadata: $this->lineageMetadata($options),
        ));

        $order = $result->order;
        if (!$order instanceof ExchangeOrderDto && $result->exchangeOrderId !== null) {
            $order = $this->adapter->getOrder($symbol, $result->exchangeOrderId);
        }
        if (!$order instanceof ExchangeOrderDto) {
            throw new \LogicException('Fake adapter returned an order result without a persisted order.');
        }

        return $this->order($order, $result->status, $result->metadata);
    }

    public function cancelOrder(string $symbol, string $orderId): bool
    {
        return $this->adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol,
            exchangeOrderId: $orderId,
        ))->cancelled;
    }

    public function getOrder(string $symbol, string $orderId): ?OrderDto
    {
        $order = $this->adapter->getOrder($symbol, $orderId);

        return $order !== null ? $this->order($order) : null;
    }

    /** @return list<OrderDto> */
    public function getOpenOrders(?string $symbol = null): array
    {
        return array_map($this->order(...), $this->adapter->getOpenOrders($symbol));
    }

    /** @return list<OrderDto> */
    public function getOpenOrdersOrFail(?string $symbol = null): array
    {
        return $this->getOpenOrders($symbol);
    }

    /** @return list<OrderDto> */
    public function getOrderHistory(string $symbol, int $limit = 100): array
    {
        if ($limit <= 0) {
            return [];
        }

        return array_map(
            $this->order(...),
            array_slice($this->adapter->getOrdersSnapshot($symbol), 0, $limit),
        );
    }

    public function cancelAllOrders(string $symbol): bool
    {
        $cancelled = true;
        foreach ($this->adapter->getOpenOrders($symbol) as $order) {
            $result = $this->adapter->cancelOrder(new CancelOrderRequest(
                exchange: Exchange::FAKE,
                marketType: MarketType::PERPETUAL,
                symbol: $symbol,
                exchangeOrderId: $order->exchangeOrderId,
            ));
            $cancelled = $result->cancelled && $cancelled;
        }

        return $cancelled;
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return $this->adapter->getOrderBookTop($symbol);
    }

    public function submitLeverage(string $symbol, int $leverage, string $openType = 'isolated'): bool
    {
        return $this->adapter->setLeverage($symbol, $leverage, $openType);
    }

    public function getProviderName(): string
    {
        return 'Fake';
    }

    /** @param array<string,mixed> $resultMetadata */
    private function order(
        ExchangeOrderDto $order,
        ?ExchangeOrderStatus $resultStatus = null,
        array $resultMetadata = [],
    ): OrderDto
    {
        $status = match ($resultStatus ?? $order->status) {
            ExchangeOrderStatus::PENDING, ExchangeOrderStatus::OPEN => OrderStatus::PENDING,
            ExchangeOrderStatus::PARTIALLY_FILLED => OrderStatus::PARTIALLY_FILLED,
            ExchangeOrderStatus::FILLED => OrderStatus::FILLED,
            ExchangeOrderStatus::CANCELLED => OrderStatus::CANCELLED,
            ExchangeOrderStatus::REJECTED => OrderStatus::REJECTED,
            ExchangeOrderStatus::EXPIRED => OrderStatus::EXPIRED,
            ExchangeOrderStatus::UNKNOWN => throw new \LogicException('Unsupported canonical Fake order status UNKNOWN.'),
        };
        $type = match ($order->orderType) {
            ExchangeOrderType::LIMIT => OrderType::LIMIT,
            ExchangeOrderType::MARKET => OrderType::MARKET,
            ExchangeOrderType::STOP_LOSS, ExchangeOrderType::TAKE_PROFIT, ExchangeOrderType::TRIGGER => OrderType::STOP,
        };

        return new OrderDto(
            orderId: $order->exchangeOrderId,
            symbol: $order->symbol,
            side: $order->side === ExchangeOrderSide::BUY ? OrderSide::BUY : OrderSide::SELL,
            type: $type,
            status: $status,
            quantity: BigDecimal::of((string) $order->quantity),
            price: $order->price !== null ? BigDecimal::of((string) $order->price) : null,
            stopPrice: $order->stopPrice !== null ? BigDecimal::of((string) $order->stopPrice) : null,
            filledQuantity: BigDecimal::of((string) $order->filledQuantity),
            remainingQuantity: BigDecimal::of((string) $order->remainingQuantity),
            averagePrice: $order->averagePrice !== null ? BigDecimal::of((string) $order->averagePrice) : null,
            createdAt: $order->createdAt,
            updatedAt: $order->updatedAt,
            filledAt: $status === OrderStatus::FILLED ? ($order->updatedAt ?? $order->createdAt) : null,
            metadata: $this->orderMetadata($order, $resultMetadata),
        );
    }

    /** @param array<string,mixed> $options */
    private function assertSupportedOptions(array $options): void
    {
        $supported = [
            'client_order_id', 'side', 'mode', 'position_side', 'margin_mode', 'open_type', 'leverage',
            'reduce_only', 'post_only', 'time_in_force',
            'attached_stop_loss_price', 'attached_take_profit_price',
            'preset_stop_loss_price', 'preset_take_profit_price',
            'preset_stop_loss_price_type', 'preset_take_profit_price_type',
            'internal_trade_id', 'trade_id', 'internal_position_id', 'position_id',
            'exchange_position_id', 'order_intent_id', 'run_id', 'correlation_run_id',
            'orchestration_run_id', 'orchestration_set_id', 'orchestration_dashboard_id',
            'mtf_profile', 'origin', 'attempt_number', 'decision_key',
        ];
        $unknown = array_diff(array_keys($options), $supported);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported Fake legacy order options: %s',
                implode(', ', $unknown),
            ));
        }

        foreach (['preset_stop_loss_price_type', 'preset_take_profit_price_type'] as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== 1) {
                throw new \InvalidArgumentException(sprintf('%s only supports the mark-price type 1.', $key));
            }
        }
    }

    private function positionSide(mixed $value, ExchangeOrderSide $fallback): ExchangePositionSide
    {
        if ($value === null) {
            return $fallback === ExchangeOrderSide::BUY ? ExchangePositionSide::LONG : ExchangePositionSide::SHORT;
        }
        if ($value instanceof ExchangePositionSide) {
            return $value;
        }
        if ($value instanceof LegacyPositionSide) {
            return ExchangePositionSide::from($value->value);
        }
        if (\is_string($value)) {
            try {
                return ExchangePositionSide::from(strtolower(trim($value)));
            } catch (\ValueError $exception) {
                throw new \InvalidArgumentException('position_side must be long or short.', 0, $exception);
            }
        }

        throw new \InvalidArgumentException('position_side has an unsupported shape.');
    }

    /**
     * @return array{side: ExchangeOrderSide, positionSide: ExchangePositionSide, reduceOnly: bool}|null
     */
    private function legacySide(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return match ($this->legacyCode($value, 'side')) {
            1 => ['side' => ExchangeOrderSide::BUY, 'positionSide' => ExchangePositionSide::LONG, 'reduceOnly' => false],
            2 => ['side' => ExchangeOrderSide::BUY, 'positionSide' => ExchangePositionSide::SHORT, 'reduceOnly' => true],
            3 => ['side' => ExchangeOrderSide::SELL, 'positionSide' => ExchangePositionSide::LONG, 'reduceOnly' => true],
            4 => ['side' => ExchangeOrderSide::SELL, 'positionSide' => ExchangePositionSide::SHORT, 'reduceOnly' => false],
            default => throw new \LogicException('Validated legacy side code is outside the supported range.'),
        };
    }

    private function legacyMode(mixed $value): ?int
    {
        return $value === null ? null : $this->legacyCode($value, 'mode');
    }

    private function legacyCode(mixed $value, string $field): int
    {
        if (\is_int($value) && $value >= 1 && $value <= 4) {
            return $value;
        }
        if (\is_string($value) && preg_match('/^[1-4]$/D', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new \InvalidArgumentException(sprintf('%s must be a legacy code from 1 to 4.', $field));
    }

    private function timeInForce(mixed $value): ExchangeTimeInForce
    {
        if ($value === null) {
            return ExchangeTimeInForce::GTC;
        }
        if ($value instanceof ExchangeTimeInForce) {
            return $value;
        }
        if (\is_string($value)) {
            try {
                return ExchangeTimeInForce::from(strtolower(trim($value)));
            } catch (\ValueError $exception) {
                throw new \InvalidArgumentException('time_in_force must be gtc, ioc, or fok.', 0, $exception);
            }
        }

        throw new \InvalidArgumentException('time_in_force has an unsupported shape.');
    }

    private function marginMode(mixed $value): string
    {
        if ($value === null) {
            return 'isolated';
        }
        if (!\is_string($value) || !\in_array(strtolower(trim($value)), ['isolated', 'cross'], true)) {
            throw new \InvalidArgumentException('margin_mode must be isolated or cross.');
        }

        return strtolower(trim($value));
    }

    /** @param array<string,mixed> $options */
    private function boolOption(array $options, string $key): bool
    {
        if (!array_key_exists($key, $options)) {
            return false;
        }
        if (!\is_bool($options[$key])) {
            throw new \InvalidArgumentException(sprintf('%s must be a boolean.', $key));
        }

        return $options[$key];
    }

    private function nullablePositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!\is_int($value) || $value <= 0) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive integer or null.', $field));
        }

        return $value;
    }

    private function nullablePositiveFloat(mixed $value, string $field): ?float
    {
        if ($value === null) {
            return null;
        }
        if (!\is_numeric($value) || !\is_finite((float) $value) || (float) $value <= 0.0) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive number or null.', $field));
        }

        return (float) $value;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function lineageMetadata(array $options): array
    {
        $metadata = [];
        foreach ([
            'internal_trade_id', 'trade_id', 'internal_position_id', 'position_id',
            'exchange_position_id', 'order_intent_id', 'run_id', 'correlation_run_id',
            'orchestration_run_id', 'orchestration_set_id', 'orchestration_dashboard_id',
            'mtf_profile', 'origin', 'attempt_number', 'decision_key',
        ] as $key) {
            if (array_key_exists($key, $options)) {
                $metadata[$key] = $options[$key];
            }
        }

        return $metadata;
    }

    /**
     * @param array<string,mixed> $resultMetadata
     * @return array<string,mixed>
     */
    private function orderMetadata(ExchangeOrderDto $order, array $resultMetadata = []): array
    {
        $canonicalMetadata = array_replace($order->metadata, $resultMetadata);
        $metadata = [
            'source' => 'fake_exchange',
            'client_order_id' => $order->clientOrderId,
            'position_side' => $order->positionSide?->value,
            'reduce_only' => $order->reduceOnly,
            'post_only' => $order->postOnly,
            'time_in_force' => $order->timeInForce?->value,
        ];
        foreach ([
            'reason', 'idempotent_replay', 'quality_flags', 'leverage', 'margin_mode', 'attached_stop_loss_price',
            'attached_take_profit_price', 'internal_trade_id', 'trade_id', 'internal_position_id',
            'position_id', 'exchange_position_id', 'order_intent_id', 'run_id',
            'correlation_run_id', 'orchestration_run_id', 'orchestration_set_id',
            'orchestration_dashboard_id', 'mtf_profile', 'origin', 'attempt_number', 'decision_key',
        ] as $key) {
            if (array_key_exists($key, $canonicalMetadata)) {
                $metadata[$key] = $canonicalMetadata[$key];
            }
        }

        return $metadata;
    }
}
