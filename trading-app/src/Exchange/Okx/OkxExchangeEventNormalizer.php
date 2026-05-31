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
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exchange_event_normalizer')]
final readonly class OkxExchangeEventNormalizer implements ExchangeEventNormalizerInterface
{
    public function __construct(
        private OkxInstrumentResolver $instruments,
        private ClockInterface $clock,
    ) {
    }

    public function supports(mixed $event): bool
    {
        if (!\is_array($event) || !\is_array($event['data'] ?? null)) {
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
        $events = match ($order->status) {
            ExchangeOrderStatus::OPEN, ExchangeOrderStatus::PENDING => [
                $this->isProtectionOrder($order, $row)
                    ? new ExchangeProtectionOrderCreated($order, $occurredAt, $row)
                    : new ExchangeOrderCreated($order, $occurredAt, $row),
            ],
            ExchangeOrderStatus::PARTIALLY_FILLED => [
                new ExchangeOrderPartiallyFilled($order, $occurredAt, $row),
            ],
            ExchangeOrderStatus::FILLED => [
                new ExchangeOrderFilled($order, $occurredAt, $row),
            ],
            ExchangeOrderStatus::CANCELLED, ExchangeOrderStatus::EXPIRED => [
                new ExchangeOrderCancelled($order, $occurredAt, $row),
            ],
            ExchangeOrderStatus::REJECTED => [
                $this->isProtectionOrder($order, $row)
                    ? new ExchangeProtectionOrderRejected($order, $occurredAt, $row)
                    : new ExchangeOrderRejected($order, $occurredAt, $row),
            ],
            default => [
                new ExchangeOrderUpdated($order, $occurredAt, $row),
            ],
        };

        $fill = $this->fillFromRow($row, 'okx_ws_orders', $preferAlgoId);
        if ($fill instanceof ExchangeFillDto) {
            $events[] = new ExchangeFillReceived($fill, $row);
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

        return $fill instanceof ExchangeFillDto ? [new ExchangeFillReceived($fill, $row)] : [];
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
                $this->positionClosedEvent($symbol, ExchangePositionSide::LONG, $occurredAt, $row),
                $this->positionClosedEvent($symbol, ExchangePositionSide::SHORT, $occurredAt, $row),
            ];
        }

        $position = $this->positionFromRow($row);
        if (!$position instanceof ExchangePositionDto) {
            return [];
        }

        $occurredAt = $position->updatedAt ?? $this->clock->now();
        if ($position->size <= 0.00000001) {
            return [$this->positionClosedEvent($position->symbol, $position->side, $occurredAt, $row)];
        }

        return [new ExchangePositionUpdated(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $position->symbol,
            side: $position->side,
            size: $position->size,
            position: $position,
            occurredAt: $occurredAt,
            payload: $row,
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
        $quantity = $this->float($row['sz'] ?? null);
        $filled = $this->orderFilledQuantity($row, $quantity, $status);
        $orderType = $this->orderType($row);
        $reduceOnly = $this->bool($row['reduceOnly'] ?? false);

        return new ExchangeOrderDto(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol,
            exchangeOrderId: $exchangeOrderId !== '' ? $exchangeOrderId : (string) $clientOrderId,
            clientOrderId: $clientOrderId,
            side: $this->orderSide($row['side'] ?? null),
            positionSide: $this->nullablePositionSide($row['posSide'] ?? null, $row['side'] ?? null, $reduceOnly),
            orderType: $orderType,
            status: $status,
            quantity: $quantity,
            filledQuantity: $filled,
            remainingQuantity: max(0.0, $quantity - $filled),
            price: $this->orderPrice($row, $orderType),
            averagePrice: $this->floatOrNull($row['avgPx'] ?? null),
            stopPrice: $this->stopPrice($row, $orderType),
            reduceOnly: $reduceOnly,
            postOnly: strtolower((string) ($row['ordType'] ?? '')) === 'post_only',
            timeInForce: $this->timeInForce($row['ordType'] ?? null),
            createdAt: $this->time($row['cTime'] ?? null),
            updatedAt: $this->timeOrNull($row['uTime'] ?? null),
            metadata: ['source' => 'okx_ws_orders'] + $row,
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
        $fillId = OkxFillId::fromTradeId($row['instId'] ?? '', $row['tradeId'] ?? null);
        if ($symbol === null || $fillId === null || $quantity <= 0.0 || $price <= 0.0 || $exchangeOrderId === '') {
            return null;
        }

        $filledAt = $this->time($row['fillTime'] ?? $row['ts'] ?? $row['uTime'] ?? null);
        $fee = $this->hasValue($row['fillFee'] ?? null) ? $row['fillFee'] : ($row['fee'] ?? null);
        $feeCurrency = $this->hasValue($row['fillFeeCcy'] ?? null)
            ? (string) $row['fillFeeCcy']
            : ($this->hasValue($row['feeCcy'] ?? null) ? (string) $row['feeCcy'] : null);
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
            metadata: ['source' => $source] + $row,
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
            metadata: ['source' => 'okx_ws_positions'] + $row,
        );
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

        return strtolower((string) ($row['ordType'] ?? '')) === 'conditional';
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
        if (strtolower((string) ($row['ordType'] ?? '')) === 'conditional') {
            return ExchangeOrderType::TRIGGER;
        }
        if (strtolower((string) ($row['ordType'] ?? '')) === 'trigger') {
            return ExchangeOrderType::TRIGGER;
        }

        if (strtolower((string) ($row['ordType'] ?? '')) === 'optimal_limit_ioc') {
            return ExchangeOrderType::MARKET;
        }

        return strtolower((string) ($row['ordType'] ?? '')) === 'market'
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
        return strtolower((string) $side) === 'sell' ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY;
    }

    private function nullablePositionSide(
        mixed $side,
        mixed $orderSide = null,
        bool $reduceOnly = false,
    ): ?ExchangePositionSide
    {
        $positionSide = strtolower((string) $side);
        if ($positionSide === 'long') {
            return ExchangePositionSide::LONG;
        }
        if ($positionSide === 'short') {
            return ExchangePositionSide::SHORT;
        }
        if ($positionSide !== 'net') {
            return null;
        }

        $isSell = strtolower((string) $orderSide) === 'sell';
        if ($reduceOnly) {
            return $isSell ? ExchangePositionSide::LONG : ExchangePositionSide::SHORT;
        }

        return null;
    }

    private function positionSide(mixed $side, float $size): ?ExchangePositionSide
    {
        return match (strtolower((string) $side)) {
            'short' => ExchangePositionSide::SHORT,
            'long' => ExchangePositionSide::LONG,
            default => $size < -0.00000001
                ? ExchangePositionSide::SHORT
                : ($size > 0.00000001 ? ExchangePositionSide::LONG : null),
        };
    }

    private function timeInForce(mixed $ordType): ExchangeTimeInForce
    {
        return match (strtolower((string) $ordType)) {
            'ioc', 'optimal_limit_ioc' => ExchangeTimeInForce::IOC,
            'fok' => ExchangeTimeInForce::FOK,
            default => ExchangeTimeInForce::GTC,
        };
    }

    private function orderStatus(mixed $state): ExchangeOrderStatus
    {
        return match (strtolower((string) $state)) {
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
        if ($preferAlgoId && $this->hasValue($row['algoId'] ?? null)) {
            return 'algo:' . (string) $row['algoId'];
        }
        if ($this->hasValue($row['ordId'] ?? null)) {
            return (string) $row['ordId'];
        }
        if ($this->hasValue($row['algoId'] ?? null)) {
            return 'algo:' . (string) $row['algoId'];
        }

        return '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function clientOrderId(array $row, bool $preferAlgoId = false): ?string
    {
        if ($preferAlgoId && $this->hasValue($row['algoClOrdId'] ?? null)) {
            return (string) $row['algoClOrdId'];
        }
        if ($this->hasValue($row['clOrdId'] ?? null)) {
            return (string) $row['clOrdId'];
        }
        if ($this->hasValue($row['algoClOrdId'] ?? null)) {
            return (string) $row['algoClOrdId'];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function symbol(array $row): ?string
    {
        if (!$this->hasValue($row['instId'] ?? null)) {
            return null;
        }

        return $this->instruments->symbol((string) $row['instId']);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isSwapRow(array $row): bool
    {
        if (isset($row['instType'])) {
            return strtoupper((string) $row['instType']) === 'SWAP';
        }

        return $this->hasValue($row['instId'] ?? null)
            && str_ends_with(strtoupper((string) $row['instId']), '-SWAP');
    }

    /**
     * @param array<string,mixed> $event
     */
    private function isSwapEvent(array $event): bool
    {
        if (!\is_array($event['arg'] ?? null) || !isset($event['arg']['instType'])) {
            return true;
        }

        return \in_array(strtoupper((string) $event['arg']['instType']), ['SWAP', 'ANY'], true);
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
        return \is_array($event['arg'] ?? null) ? (string) ($event['arg']['channel'] ?? '') : '';
    }

    private function time(mixed $milliseconds): \DateTimeImmutable
    {
        return $this->timeOrNull($milliseconds) ?? $this->clock->now();
    }

    private function timeOrNull(mixed $milliseconds): ?\DateTimeImmutable
    {
        if (!is_numeric($milliseconds)) {
            return null;
        }

        $milliseconds = (int) floor((float) $milliseconds);
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
        return \filter_var($value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    private function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
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
    private function orderFilledQuantity(array $row, float $quantity, ExchangeOrderStatus $status): float
    {
        $filled = $this->float($row['accFillSz'] ?? null);
        if ($filled > 0.0 || $status !== ExchangeOrderStatus::FILLED) {
            return $filled;
        }

        return $quantity;
    }

    private function hasValue(mixed $value): bool
    {
        return trim((string) $value) !== '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isNetModeZeroPosition(array $row): bool
    {
        return strtolower((string) ($row['posSide'] ?? '')) === 'net'
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
