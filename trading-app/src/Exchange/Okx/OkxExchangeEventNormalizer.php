<?php

declare(strict_types=1);

namespace App\Exchange\Okx;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeEventNormalizerInterface;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeOrderCancelled;
use App\Exchange\Event\ExchangeOrderCreated;
use App\Exchange\Event\ExchangeOrderFilled;
use App\Exchange\Event\ExchangeOrderPartiallyFilled;
use App\Exchange\Event\ExchangeOrderRejected;
use App\Exchange\Event\ExchangeOrderUpdated;
use App\Exchange\Event\ExchangePositionClosed;
use App\Exchange\Event\ExchangePositionUpdated;
use App\Exchange\Event\ExchangeProtectionOrderCreated;
use App\Exchange\Event\ExchangeProtectionOrderRejected;
use App\Exchange\Value\ExactOrderQuantities;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exchange_event_normalizer')]
final readonly class OkxExchangeEventNormalizer implements ExchangeEventNormalizerInterface
{
    private const ROW_SCALAR_KEYS = [
        'accFillSz',
        'algoClOrdId',
        'algoId',
        'avgPx',
        'cTime',
        'clOrdId',
        'execType',
        'fee',
        'feeCcy',
        'fillFee',
        'fillFeeCcy',
        'fillPx',
        'fillSz',
        'fillTime',
        'imr',
        'instId',
        'instType',
        'lever',
        'margin',
        'markPx',
        'mgnMode',
        'ordId',
        'ordPx',
        'ordType',
        'pos',
        'posSide',
        'px',
        'realizedPnl',
        'reduceOnly',
        'side',
        'slOrdPx',
        'slTriggerPx',
        'state',
        'sz',
        'tdMode',
        'tpOrdPx',
        'tpTriggerPx',
        'tradeId',
        'triggerPx',
        'ts',
        'uTime',
        'upl',
    ];

    public function __construct(
        private OkxInstrumentResolver $instruments,
        private ClockInterface $clock,
    ) {
    }

    public function supports(mixed $event): bool
    {
        if (!\is_array($event) || !\is_array($event['arg'] ?? null) || !\is_array($event['data'] ?? null)) {
            return false;
        }
        if ($this->channel($event) === '') {
            return false;
        }
        if (\array_key_exists('instType', $event['arg']) && $this->scalarString($event['arg']['instType']) === null) {
            return false;
        }

        return $this->isSwapEvent($event)
            && \in_array($this->channel($event), ['orders', 'orders-algo', 'fills', 'positions'], true);
    }

    /**
     * @return ExchangeEventInterface[]
     */
    public function normalize(mixed $event): array
    {
        if (!$this->supports($event)) {
            return [];
        }

        /** @var array<string,mixed> $event */
        $normalized = [];
        $channel = $this->channel($event);
        foreach ($this->dataRows($event) as $row) {
            if (!$this->hasValidRowShapes($row)) {
                throw new \InvalidArgumentException('okx_private_ws_message_invalid');
            }
            if (!$this->isSwapRow($row)) {
                continue;
            }
            $this->assertValidRowValues($row, $channel);
            array_push($normalized, ...match ($channel) {
                'orders', 'orders-algo' => $this->orderEvents($row, $channel),
                'fills' => $this->fillEvents($row),
                'positions' => $this->positionEvents($row),
                default => [],
            });
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $row
     * @return ExchangeEventInterface[]
     */
    private function orderEvents(array $row, string $channel): array
    {
        $preferAlgoId = $channel === 'orders-algo';
        $order = $this->orderFromRow($row, $preferAlgoId);
        if (!$order instanceof ExchangeOrderDto) {
            return [];
        }

        $occurredAt = $this->time($row['uTime'] ?? $row['fillTime'] ?? $row['cTime'] ?? null);
        $payload = $this->orderPayload($row, $preferAlgoId);
        $events = match ($order->status) {
            ExchangeOrderStatus::OPEN, ExchangeOrderStatus::PENDING => [
                $this->isProtectionOrder($order, $row)
                    ? new ExchangeProtectionOrderCreated($order, $occurredAt, $payload)
                    : new ExchangeOrderCreated($order, $occurredAt, $payload),
            ],
            ExchangeOrderStatus::PARTIALLY_FILLED => [
                new ExchangeOrderPartiallyFilled($order, $occurredAt, $payload),
            ],
            ExchangeOrderStatus::FILLED => [
                new ExchangeOrderFilled($order, $occurredAt, $payload),
            ],
            ExchangeOrderStatus::CANCELLED, ExchangeOrderStatus::EXPIRED => [
                new ExchangeOrderCancelled($order, $occurredAt, $payload),
            ],
            ExchangeOrderStatus::REJECTED => [
                $this->isProtectionOrder($order, $row)
                    ? new ExchangeProtectionOrderRejected($order, $occurredAt, $payload)
                    : new ExchangeOrderRejected($order, $occurredAt, $payload),
            ],
            default => [
                new ExchangeOrderUpdated($order, $occurredAt, $payload),
            ],
        };

        $fill = $this->fillFromRow($row, 'okx_ws_orders', $preferAlgoId);
        if ($fill instanceof ExchangeFillDto) {
            $events[] = new ExchangeFillReceived($fill, $this->fillPayload($row, 'okx_ws_orders', $preferAlgoId));
        }

        return $events;
    }

    /**
     * @param array<string,mixed> $row
     * @return ExchangeEventInterface[]
     */
    private function fillEvents(array $row): array
    {
        $fill = $this->fillFromRow($row, 'okx_ws_fills');

        return $fill instanceof ExchangeFillDto
            ? [new ExchangeFillReceived($fill, $this->fillPayload($row, 'okx_ws_fills'))]
            : [];
    }

    /**
     * @param array<string,mixed> $row
     * @return ExchangeEventInterface[]
     */
    private function positionEvents(array $row): array
    {
        if ($this->isNetModeZeroPosition($row)) {
            $symbol = $this->symbol($row);
            if ($symbol === null) {
                return [];
            }

            $occurredAt = $this->time($row['uTime'] ?? null);

            return [
                $this->positionClosedEvent($symbol, ExchangePositionSide::LONG, $occurredAt, $this->positionPayload($row)),
                $this->positionClosedEvent($symbol, ExchangePositionSide::SHORT, $occurredAt, $this->positionPayload($row)),
            ];
        }

        $position = $this->positionFromRow($row);
        if (!$position instanceof ExchangePositionDto) {
            return [];
        }

        $occurredAt = $position->updatedAt ?? $this->clock->now();
        if ($position->size <= 0.00000001) {
            return [$this->positionClosedEvent($position->symbol, $position->side, $occurredAt, $this->positionPayload($row))];
        }

        return [new ExchangePositionUpdated(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $position->symbol,
            side: $position->side,
            size: $position->size,
            position: $position,
            occurredAt: $occurredAt,
            payload: $this->positionPayload($row),
        )];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function orderFromRow(array $row, bool $preferAlgoId): ?ExchangeOrderDto
    {
        if (!$this->isSwapRow($row)) {
            return null;
        }

        $symbol = $this->symbol($row);
        if ($symbol === null) {
            return null;
        }

        $exchangeOrderId = $this->orderId($row, $preferAlgoId);
        $clientOrderId = $this->clientOrderId($row, $preferAlgoId);
        if ($exchangeOrderId === '' && ($clientOrderId === null || $clientOrderId === '')) {
            return null;
        }

        $status = $this->orderStatus($row['state'] ?? null);
        $exactQuantities = $this->orderExactQuantities($row, $status);
        if ($exactQuantities === null) {
            return null;
        }
        $quantity = $exactQuantities->quantityValue()->toFloat();
        $filled = $exactQuantities->filledValue()->toFloat();
        $orderType = $this->orderType($row);
        $reduceOnly = $this->bool($row['reduceOnly'] ?? false);

        return new ExchangeOrderDto(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol,
            exchangeOrderId: $exchangeOrderId !== '' ? $exchangeOrderId : $clientOrderId,
            clientOrderId: $clientOrderId,
            side: $this->orderSide($row['side'] ?? null),
            positionSide: $this->nullablePositionSide($row['posSide'] ?? null, $row['side'] ?? null, $reduceOnly),
            orderType: $orderType,
            status: $status,
            quantity: $quantity,
            filledQuantity: $filled,
            remainingQuantity: $exactQuantities->remainingValue()->toFloat(),
            price: $this->orderPrice($row, $orderType),
            averagePrice: $this->floatOrNull($row['avgPx'] ?? null),
            stopPrice: $this->stopPrice($row, $orderType),
            reduceOnly: $reduceOnly,
            postOnly: $this->lowerScalar($row['ordType'] ?? null) === 'post_only',
            timeInForce: $this->timeInForce($row['ordType'] ?? null),
            createdAt: $this->time($row['cTime'] ?? null),
            updatedAt: $this->timeOrNull($row['uTime'] ?? null),
            metadata: $this->withoutNullStrings([
                'source' => 'okx_ws_orders',
                'instrument_id' => $this->scalarString($row['instId'] ?? null),
                'margin_mode' => $this->marginMode($row['tdMode'] ?? null),
                'leverage' => $this->numericString($row['lever'] ?? null),
                'quantity_decimal' => $exactQuantities->quantity,
                'filled_quantity_decimal' => $exactQuantities->filled,
                'remaining_quantity_decimal' => $exactQuantities->remaining,
            ]),
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function fillFromRow(array $row, string $source, bool $preferAlgoId = false): ?ExchangeFillDto
    {
        if (!$this->isSwapRow($row)) {
            return null;
        }

        $symbol = $this->symbol($row);
        $quantity = $this->fillQuantity($row);
        $price = $this->fillPrice($row);
        $exchangeOrderId = $this->orderId($row, $preferAlgoId);
        $fillId = OkxFillId::fromTradeId(
            $this->scalarString($row['instId'] ?? null),
            $this->scalarString($row['tradeId'] ?? null),
        );
        if ($symbol === null || $fillId === null || $quantity <= 0.0 || $price <= 0.0 || $exchangeOrderId === '') {
            return null;
        }

        $filledAt = $this->time($row['fillTime'] ?? $row['ts'] ?? $row['uTime'] ?? null);
        $fee = $this->hasValue($row['fillFee'] ?? null) ? $row['fillFee'] : ($row['fee'] ?? null);
        $feeCurrency = $this->scalarString($row['fillFeeCcy'] ?? null)
            ?? $this->scalarString($row['feeCcy'] ?? null);
        $reduceOnly = $this->bool($row['reduceOnly'] ?? false);

        return new ExchangeFillDto(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol,
            exchangeOrderId: $exchangeOrderId,
            clientOrderId: $this->clientOrderId($row, $preferAlgoId),
            fillId: $fillId,
            side: $this->orderSide($row['side'] ?? null),
            positionSide: $this->nullablePositionSide($row['posSide'] ?? null, $row['side'] ?? null, $reduceOnly),
            quantity: $quantity,
            price: $price,
            fee: $this->floatOrNull($fee),
            feeCurrency: $feeCurrency,
            filledAt: $filledAt,
            metadata: $this->withoutNullStrings([
                'source' => $source,
                'instrument_id' => $this->scalarString($row['instId'] ?? null),
                'exchange_fill_id' => $this->scalarString($row['tradeId'] ?? null),
                'liquidity_role' => $this->liquidityRole($row['execType'] ?? null),
            ]),
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function positionFromRow(array $row): ?ExchangePositionDto
    {
        if (!$this->isSwapRow($row)) {
            return null;
        }

        $symbol = $this->symbol($row);
        if ($symbol === null) {
            return null;
        }

        $size = $this->float($row['pos'] ?? null);
        $side = $this->positionSide($row['posSide'] ?? null, $size);
        if (!$side instanceof ExchangePositionSide) {
            return null;
        }

        return new ExchangePositionDto(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol,
            side: $side,
            size: abs($size),
            entryPrice: $this->float($row['avgPx'] ?? null),
            markPrice: $this->floatOrNull($row['markPx'] ?? null),
            unrealizedPnl: $this->floatOrNull($row['upl'] ?? null),
            realizedPnl: $this->floatOrNull($row['realizedPnl'] ?? null),
            margin: $this->floatOrNull($row['margin'] ?? $row['imr'] ?? null),
            leverage: $this->floatOrNull($row['lever'] ?? null),
            updatedAt: $this->timeOrNull($row['uTime'] ?? null),
            metadata: $this->withoutNullStrings([
                'source' => 'okx_ws_positions',
                'instrument_id' => $this->scalarString($row['instId'] ?? null),
                'margin_mode' => $this->marginMode($row['mgnMode'] ?? null),
            ]),
        );
    }

    /**
     * Private provider rows are never forwarded. These channel-aware builders are
     * the positive allowlist for event payloads; non-scalar values are dropped.
     *
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private function orderPayload(array $row, bool $preferAlgoId): array
    {
        return $this->withoutNullStrings([
            'source' => 'okx_ws_orders',
            'instrument_id' => $this->scalarString($row['instId'] ?? null),
            'exchange_order_id' => $this->orderId($row, $preferAlgoId),
            'client_order_id' => $this->clientOrderId($row, $preferAlgoId),
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private function fillPayload(array $row, string $source, bool $preferAlgoId = false): array
    {
        return $this->withoutNullStrings([
            'source' => $source,
            'instrument_id' => $this->scalarString($row['instId'] ?? null),
            'exchange_order_id' => $this->orderId($row, $preferAlgoId),
            'client_order_id' => $this->clientOrderId($row, $preferAlgoId),
            'exchange_fill_id' => $this->scalarString($row['tradeId'] ?? null),
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private function positionPayload(array $row): array
    {
        return $this->withoutNullStrings([
            'source' => 'okx_ws_positions',
            'instrument_id' => $this->scalarString($row['instId'] ?? null),
        ]);
    }

    /**
     * @param array<string,?string> $values
     * @return array<string,string>
     */
    private function withoutNullStrings(array $values): array
    {
        return array_filter(
            $values,
            static fn (?string $value): bool => $value !== null && $value !== '',
        );
    }

    private function scalarString(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function lowerScalar(mixed $value): string
    {
        return strtolower($this->scalarString($value) ?? '');
    }

    private function upperScalar(mixed $value): string
    {
        return strtoupper($this->scalarString($value) ?? '');
    }

    private function numericString(mixed $value): ?string
    {
        $value = $this->scalarString($value);

        return $value !== null && $this->isFiniteDecimal($value) ? $value : null;
    }

    private function marginMode(mixed $value): ?string
    {
        $value = $this->lowerScalar($value);

        return \in_array($value, ['cross', 'isolated'], true) ? $value : null;
    }

    private function liquidityRole(mixed $value): ?string
    {
        return match ($this->lowerScalar($value)) {
            'm', 'maker' => 'maker',
            't', 'taker' => 'taker',
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isProtectionOrder(ExchangeOrderDto $order, array $row): bool
    {
        if (\in_array($order->orderType, [ExchangeOrderType::STOP_LOSS, ExchangeOrderType::TAKE_PROFIT], true)) {
            return true;
        }
        if ($order->orderType === ExchangeOrderType::TRIGGER && $order->reduceOnly) {
            return true;
        }

        return $this->lowerScalar($row['ordType'] ?? null) === 'conditional';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function orderType(array $row): ExchangeOrderType
    {
        if ($this->hasValue($row['tpTriggerPx'] ?? null)) {
            return ExchangeOrderType::TAKE_PROFIT;
        }
        if ($this->hasValue($row['slTriggerPx'] ?? null)) {
            return ExchangeOrderType::STOP_LOSS;
        }
        if ($this->lowerScalar($row['ordType'] ?? null) === 'conditional') {
            return ExchangeOrderType::TRIGGER;
        }
        if ($this->lowerScalar($row['ordType'] ?? null) === 'trigger') {
            return ExchangeOrderType::TRIGGER;
        }

        if ($this->lowerScalar($row['ordType'] ?? null) === 'optimal_limit_ioc') {
            return ExchangeOrderType::MARKET;
        }

        return $this->lowerScalar($row['ordType'] ?? null) === 'market'
            ? ExchangeOrderType::MARKET
            : ExchangeOrderType::LIMIT;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function stopPrice(array $row, ExchangeOrderType $orderType): ?float
    {
        if ($orderType === ExchangeOrderType::TAKE_PROFIT) {
            return $this->floatOrNull($row['tpTriggerPx'] ?? null);
        }
        if ($orderType === ExchangeOrderType::STOP_LOSS) {
            return $this->floatOrNull($row['slTriggerPx'] ?? null);
        }
        if ($orderType === ExchangeOrderType::TRIGGER) {
            return $this->floatOrNull($row['triggerPx'] ?? null);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function orderPrice(array $row, ExchangeOrderType $orderType): ?float
    {
        $candidate = match ($orderType) {
            ExchangeOrderType::TAKE_PROFIT => $row['tpOrdPx'] ?? null,
            ExchangeOrderType::STOP_LOSS => $row['slOrdPx'] ?? null,
            ExchangeOrderType::TRIGGER => $row['ordPx'] ?? null,
            default => $row['px'] ?? null,
        };

        $price = $this->floatOrNull($candidate);
        if ($price !== null && $price > 0.0) {
            return $price;
        }

        return $this->floatOrNull($row['px'] ?? null);
    }

    private function orderSide(mixed $side): ExchangeOrderSide
    {
        return $this->lowerScalar($side) === 'sell' ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY;
    }

    private function nullablePositionSide(
        mixed $side,
        mixed $orderSide = null,
        bool $reduceOnly = false,
    ): ?ExchangePositionSide
    {
        $positionSide = $this->lowerScalar($side);
        if ($positionSide === 'long') {
            return ExchangePositionSide::LONG;
        }
        if ($positionSide === 'short') {
            return ExchangePositionSide::SHORT;
        }
        if ($positionSide !== 'net') {
            return null;
        }

        $isSell = $this->lowerScalar($orderSide) === 'sell';
        if ($reduceOnly) {
            return $isSell ? ExchangePositionSide::LONG : ExchangePositionSide::SHORT;
        }

        return null;
    }

    private function positionSide(mixed $side, float $size): ?ExchangePositionSide
    {
        return match ($this->lowerScalar($side)) {
            'short' => ExchangePositionSide::SHORT,
            'long' => ExchangePositionSide::LONG,
            default => $size < -0.00000001
                ? ExchangePositionSide::SHORT
                : ($size > 0.00000001 ? ExchangePositionSide::LONG : null),
        };
    }

    private function timeInForce(mixed $ordType): ExchangeTimeInForce
    {
        return match ($this->lowerScalar($ordType)) {
            'ioc', 'optimal_limit_ioc' => ExchangeTimeInForce::IOC,
            'fok' => ExchangeTimeInForce::FOK,
            default => ExchangeTimeInForce::GTC,
        };
    }

    private function orderStatus(mixed $state): ExchangeOrderStatus
    {
        return match ($this->lowerScalar($state)) {
            'filled' => ExchangeOrderStatus::FILLED,
            'partially_filled' => ExchangeOrderStatus::PARTIALLY_FILLED,
            'canceled', 'cancelled', 'mmp_canceled' => ExchangeOrderStatus::CANCELLED,
            'rejected', 'order_failed', 'partially_failed' => ExchangeOrderStatus::REJECTED,
            'effective', 'partially_effective' => ExchangeOrderStatus::UNKNOWN,
            'live' => ExchangeOrderStatus::OPEN,
            default => ExchangeOrderStatus::PENDING,
        };
    }

    /**
     * @param array<string,mixed> $row
     */
    private function orderId(array $row, bool $preferAlgoId = false): string
    {
        $algoId = $this->scalarString($row['algoId'] ?? null);
        $orderId = $this->scalarString($row['ordId'] ?? null);
        if ($preferAlgoId && $algoId !== null) {
            return 'algo:' . $algoId;
        }
        if ($orderId !== null) {
            return $orderId;
        }
        if ($algoId !== null) {
            return 'algo:' . $algoId;
        }

        return '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function clientOrderId(array $row, bool $preferAlgoId = false): ?string
    {
        $algoClientOrderId = $this->scalarString($row['algoClOrdId'] ?? null);
        $clientOrderId = $this->scalarString($row['clOrdId'] ?? null);
        if ($preferAlgoId && $algoClientOrderId !== null) {
            return $algoClientOrderId;
        }
        if ($clientOrderId !== null) {
            return $clientOrderId;
        }
        if ($algoClientOrderId !== null) {
            return $algoClientOrderId;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function symbol(array $row): ?string
    {
        $instrumentId = $this->scalarString($row['instId'] ?? null);
        if ($instrumentId === null) {
            return null;
        }

        return $this->instruments->symbol($instrumentId);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isSwapRow(array $row): bool
    {
        if (isset($row['instType'])) {
            return $this->upperScalar($row['instType']) === 'SWAP';
        }

        return str_ends_with($this->upperScalar($row['instId'] ?? null), '-SWAP');
    }

    /**
     * @param array<string,mixed> $event
     */
    private function isSwapEvent(array $event): bool
    {
        if (!\is_array($event['arg'] ?? null) || !isset($event['arg']['instType'])) {
            return true;
        }

        return \in_array($this->upperScalar($event['arg']['instType']), ['SWAP', 'ANY'], true);
    }

    /**
     * @param array<string,mixed> $event
     * @return list<array<string,mixed>>
     */
    private function dataRows(array $event): array
    {
        $data = $event['data'] ?? [];
        if (!\is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, \is_array(...)));
    }

    /**
     * @param array<string,mixed> $event
     */
    private function channel(array $event): string
    {
        return \is_array($event['arg'] ?? null)
            ? ($this->scalarString($event['arg']['channel'] ?? null) ?? '')
            : '';
    }

    private function time(mixed $milliseconds): \DateTimeImmutable
    {
        return $this->timeOrNull($milliseconds) ?? $this->clock->now();
    }

    private function timeOrNull(mixed $milliseconds): ?\DateTimeImmutable
    {
        $milliseconds = $this->scalarString($milliseconds);
        if ($milliseconds === null || !$this->isValidTimestamp($milliseconds)) {
            return null;
        }

        $milliseconds = (int) $milliseconds;
        $seconds = intdiv($milliseconds, 1000);
        $microseconds = ($milliseconds % 1000) * 1000;
        $time = \DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', $seconds, $microseconds),
            new \DateTimeZone('UTC'),
        );

        return $time instanceof \DateTimeImmutable ? $time->setTimezone(new \DateTimeZone('UTC')) : null;
    }

    private function bool(mixed $value): bool
    {
        $value = $this->scalarString($value);

        return $value !== null
            && (\filter_var($value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? false);
    }

    private function float(mixed $value): float
    {
        return $this->floatOrNull($value) ?? 0.0;
    }

    private function floatOrNull(mixed $value): ?float
    {
        $value = $this->scalarString($value);
        if ($value === null || !$this->isFiniteDecimal($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function fillQuantity(array $row): float
    {
        return $this->float($row['fillSz'] ?? null);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function fillPrice(array $row): float
    {
        return $this->float($row['fillPx'] ?? null);
    }

    /**
     * @param array<string,mixed> $row
     */
    /**
     * @param array<string,mixed> $row
     */
    private function orderExactQuantities(array $row, ExchangeOrderStatus $status): ?ExactOrderQuantities
    {
        $quantity = $row['sz'] ?? null;
        if (!\is_string($quantity)) {
            return null;
        }
        $filled = $row['accFillSz'] ?? null;
        if (!\is_string($filled)) {
            if ($this->hasValue($row['accFillSz'] ?? null)) {
                return null;
            }
            $filled = $status === ExchangeOrderStatus::FILLED
                ? $quantity
                : '0';
        }

        try {
            return ExactOrderQuantities::fromQuantityAndFilled($quantity, $filled);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('okx_private_ws_message_invalid');
        }
    }

    private function hasValue(mixed $value): bool
    {
        return $this->scalarString($value) !== null;
    }

    /** @param array<string,mixed> $row */
    private function hasValidRowShapes(array $row): bool
    {
        foreach (self::ROW_SCALAR_KEYS as $key) {
            if (\array_key_exists($key, $row) && $row[$key] !== null && !\is_scalar($row[$key])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $row */
    private function assertValidRowValues(array $row, string $channel): void
    {
        $this->assertKnownEnum($row, 'side', ['buy', 'sell']);
        $this->assertKnownEnum($row, 'state', [
            'filled', 'partially_filled', 'canceled', 'cancelled', 'mmp_canceled',
            'rejected', 'order_failed', 'partially_failed', 'effective',
            'partially_effective', 'live',
        ]);
        $this->assertKnownEnum($row, 'ordType', [
            'limit', 'market', 'post_only', 'ioc', 'fok', 'optimal_limit_ioc',
            'conditional', 'trigger', 'oco', 'move_order_stop', 'iceberg', 'twap',
        ]);
        $this->assertKnownEnum($row, 'posSide', ['long', 'short', 'net']);
        $this->assertKnownEnum($row, 'tdMode', ['cross', 'isolated', 'cash', 'simulated']);
        $this->assertKnownEnum($row, 'mgnMode', ['cross', 'isolated', 'cash', 'simulated']);
        $this->assertKnownEnum($row, 'reduceOnly', ['true', 'false']);

        foreach ([
            'accFillSz',
            'avgPx',
            'fee',
            'fillFee',
            'fillPx',
            'fillSz',
            'imr',
            'lever',
            'margin',
            'markPx',
            'ordPx',
            'pos',
            'px',
            'realizedPnl',
            'slOrdPx',
            'slTriggerPx',
            'sz',
            'tpOrdPx',
            'tpTriggerPx',
            'triggerPx',
            'upl',
        ] as $key) {
            if ($this->hasProvidedValue($row, $key) && !$this->isFiniteDecimal($this->scalarString($row[$key]) ?? '')) {
                throw new \InvalidArgumentException('okx_private_ws_message_invalid');
            }
        }

        foreach (['accFillSz', 'avgPx', 'fillPx', 'fillSz', 'imr', 'lever', 'margin', 'markPx', 'sz'] as $key) {
            if ($this->hasProvidedValue($row, $key) && ($this->floatOrNull($row[$key]) ?? -1.0) < 0.0) {
                throw new \InvalidArgumentException('okx_private_ws_message_invalid');
            }
        }

        if ($this->hasProvidedValue($row, 'lever') && ($this->floatOrNull($row['lever']) ?? 0.0) <= 0.0) {
            throw new \InvalidArgumentException('okx_private_ws_message_invalid');
        }
        if ($channel === 'fills') {
            foreach (['fillPx', 'fillSz'] as $key) {
                if ($this->hasProvidedValue($row, $key) && ($this->floatOrNull($row[$key]) ?? 0.0) <= 0.0) {
                    throw new \InvalidArgumentException('okx_private_ws_message_invalid');
                }
            }
        }
        if (\in_array($channel, ['orders', 'orders-algo'], true)
            && $this->hasProvidedValue($row, 'tdMode')
            && $this->marginMode($row['tdMode']) === null) {
            throw new \InvalidArgumentException('okx_private_ws_message_invalid');
        }
        if ($channel === 'positions'
            && $this->hasProvidedValue($row, 'mgnMode')
            && $this->marginMode($row['mgnMode']) === null) {
            throw new \InvalidArgumentException('okx_private_ws_message_invalid');
        }

        foreach (['cTime', 'fillTime', 'ts', 'uTime'] as $key) {
            if ($this->hasProvidedValue($row, $key) && !$this->isValidTimestamp($this->scalarString($row[$key]) ?? '')) {
                throw new \InvalidArgumentException('okx_private_ws_message_invalid');
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $allowed
     */
    private function assertKnownEnum(array $row, string $key, array $allowed): void
    {
        if (!array_key_exists($key, $row) || $row[$key] === null) {
            return;
        }

        $value = $this->lowerScalar($row[$key]);
        if ($value === '' && in_array($key, ['posSide', 'tdMode', 'mgnMode'], true)) {
            return;
        }
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException('okx_private_ws_message_invalid');
        }
    }

    /** @param array<string,mixed> $row */
    private function hasProvidedValue(array $row, string $key): bool
    {
        if (!\array_key_exists($key, $row) || $row[$key] === null) {
            return false;
        }

        return !\is_string($row[$key]) || trim($row[$key]) !== '';
    }

    private function isFiniteDecimal(string $value): bool
    {
        if (preg_match('/^-?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $value) !== 1) {
            return false;
        }

        return is_finite((float) $value);
    }

    private function isValidTimestamp(string $value): bool
    {
        if (preg_match('/^\d+$/D', $value) !== 1) {
            return false;
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = '9999999999999';

        return strlen($normalized) < strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) <= 0);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isNetModeZeroPosition(array $row): bool
    {
        return $this->lowerScalar($row['posSide'] ?? null) === 'net'
            && abs($this->float($row['pos'] ?? null)) <= 0.00000001;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function positionClosedEvent(
        string $symbol,
        ExchangePositionSide $side,
        \DateTimeImmutable $occurredAt,
        array $payload,
    ): ExchangePositionClosed {
        return new ExchangePositionClosed(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol,
            side: $side,
            size: 0.0,
            position: null,
            occurredAt: $occurredAt,
            payload: $payload,
        );
    }

}
