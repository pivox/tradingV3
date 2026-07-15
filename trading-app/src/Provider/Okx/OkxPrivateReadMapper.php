<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderStatus;
use App\Common\Enum\OrderType;
use App\Common\Enum\PositionSide;
use App\Contract\Provider\Dto\AccountDto;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\PositionDto;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Okx\OkxFillId;
use App\Exchange\Okx\OkxInstrumentResolver;
use Brick\Math\BigDecimal;

final readonly class OkxPrivateReadMapper
{
    public function __construct(
        private OkxInstrumentResolver $instruments = new OkxInstrumentResolver(),
    ) {
    }

    /**
     * @param array<string,mixed> $account
     */
    public function account(array $account, string $currency): ?AccountDto
    {
        $wanted = strtoupper($currency);
        foreach ($this->details($account) as $detail) {
            $ccy = strtoupper($this->string($detail['ccy'] ?? ''));
            if ($ccy === '' || $ccy !== $wanted) {
                continue;
            }

            return new AccountDto(
                currency: $ccy,
                availableBalance: BigDecimal::of($this->firstNumber($detail['availEq'] ?? null, $detail['availBal'] ?? null, '0')),
                frozenBalance: BigDecimal::of($this->firstNumber($detail['frozenBal'] ?? null, $detail['ordFrozen'] ?? null, '0')),
                unrealized: BigDecimal::of($this->firstNumber($detail['upl'] ?? null, '0')),
                equity: BigDecimal::of($this->firstNumber($detail['eq'] ?? null, $account['totalEq'] ?? null, '0')),
                positionDeposit: BigDecimal::of($this->firstNumber($detail['imr'] ?? null, $detail['margin'] ?? null, '0')),
                metadata: $this->redacted($detail),
            );
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function position(array $row): ?PositionDto
    {
        $this->assertKnownEnums($row);
        $size = $this->float($row['pos'] ?? null);
        if (abs($size) <= 0.00000001) {
            return null;
        }

        $side = $this->positionSide($row['posSide'] ?? null, $size);
        if (!$side instanceof PositionSide) {
            return null;
        }

        return new PositionDto(
            symbol: $this->instruments->symbol($this->string($row['instId'] ?? '')),
            side: $side,
            size: BigDecimal::of($this->number(abs($size))),
            entryPrice: BigDecimal::of($this->number($row['avgPx'] ?? '0')),
            markPrice: BigDecimal::of($this->number($row['markPx'] ?? '0')),
            unrealizedPnl: BigDecimal::of($this->number($row['upl'] ?? '0')),
            realizedPnl: BigDecimal::of($this->number($row['realizedPnl'] ?? '0')),
            margin: BigDecimal::of($this->number($row['margin'] ?? $row['imr'] ?? '0')),
            leverage: BigDecimal::of($this->number($row['lever'] ?? '1')),
            openedAt: $this->time($row['cTime'] ?? $row['uTime'] ?? null),
            metadata: $this->redacted($row),
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    public function order(array $row, bool $algo): OrderDto
    {
        $this->assertKnownEnums($row);
        $quantity = BigDecimal::of($this->number($row['sz'] ?? '0'));
        $filled = BigDecimal::of($this->number($row['accFillSz'] ?? '0'));
        $orderType = $this->orderType($row, $algo);
        $orderId = $algo ? 'algo:' . $this->string($row['algoId'] ?? '') : $this->string($row['ordId'] ?? '');

        return new OrderDto(
            orderId: $orderId,
            symbol: $this->instruments->symbol($this->string($row['instId'] ?? '')),
            side: $this->orderSide($row['side'] ?? null),
            type: $orderType,
            status: $this->orderStatus($row['state'] ?? null),
            quantity: $quantity,
            price: $this->decimalOrNull($row['px'] ?? null),
            stopPrice: $this->stopPrice($row, $orderType),
            filledQuantity: $filled,
            remainingQuantity: $quantity->minus($filled),
            averagePrice: $this->decimalOrNull($row['avgPx'] ?? null),
            createdAt: $this->time($row['cTime'] ?? $row['uTime'] ?? null),
            updatedAt: $this->hasTimestamp($row['uTime'] ?? null) ? $this->time($row['uTime']) : null,
            metadata: $this->orderMetadata($row, $algo),
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    public function fill(array $row): ExchangeFillDto
    {
        $this->assertKnownEnums($row);
        return new ExchangeFillDto(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $this->instruments->symbol($this->string($row['instId'] ?? '')),
            exchangeOrderId: $this->string($row['ordId'] ?? ''),
            clientOrderId: $this->stringOrNull($row['clOrdId'] ?? null),
            fillId: OkxFillId::fromTradeId($row['instId'] ?? '', $row['tradeId'] ?? null),
            side: $this->exchangeOrderSide($row['side'] ?? null),
            positionSide: $this->exchangePositionSide($row['posSide'] ?? null),
            quantity: $this->float($row['fillSz'] ?? null),
            price: $this->float($row['fillPx'] ?? null),
            fee: $this->floatOrNull($row['fee'] ?? null),
            feeCurrency: $this->stringOrNull($row['feeCcy'] ?? null),
            filledAt: $this->time($row['ts'] ?? null),
            metadata: $this->redacted($row),
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function legacyTrade(array $row): array
    {
        $this->assertKnownEnums($row);
        $trade = [
            'exchange' => 'okx',
            'instrument_id' => $this->string($row['instId'] ?? ''),
            'symbol' => $this->instruments->symbol($this->string($row['instId'] ?? '')),
            'order_id' => $this->string($row['ordId'] ?? ''),
            'client_order_id' => $this->stringOrNull($row['clOrdId'] ?? null),
            'trade_id' => $this->string($row['tradeId'] ?? ''),
            'side' => strtolower($this->string($row['side'] ?? '')),
            'position_side' => strtolower($this->string($row['posSide'] ?? '')),
            'open_type' => $this->legacyOpenType($row['side'] ?? null, $row['posSide'] ?? null),
            'size' => $this->number($row['fillSz'] ?? $row['sz'] ?? '0'),
            'price' => $this->number($row['fillPx'] ?? $row['px'] ?? '0'),
            'fee_currency' => $this->stringOrNull($row['feeCcy'] ?? null),
            'create_time' => $this->intOrNull($row['fillTime'] ?? null) ?? $this->intOrNull($row['ts'] ?? null),
            'raw_reference' => $this->redacted($row),
        ];
        if (array_key_exists('fee', $row)) {
            $trade['fee'] = $this->number($row['fee']);
        }

        return $trade;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function legacyTransaction(array $row, ?int $requestedFlowType): array
    {
        $transaction = [
            'exchange' => 'okx',
            'symbol' => $this->instruments->symbol($this->string($row['instId'] ?? '')),
            'flow_type' => $requestedFlowType ?? $this->intOrNull($row['type'] ?? $row['subType'] ?? null),
            'amount' => $this->firstNumber($row['pnl'] ?? null, $row['balChg'] ?? null, $row['amt'] ?? null, '0'),
            'currency' => $this->stringOrNull($row['ccy'] ?? null),
            'create_time' => is_numeric($row['ts'] ?? null) ? (int) $row['ts'] : null,
            'raw_reference' => $this->redacted($row),
        ];
        if (array_key_exists('fee', $row)) {
            $transaction['fee'] = $this->number($row['fee']);
        }

        return $transaction;
    }

    /**
     * @param array<string,mixed> $account
     * @return list<array<string,mixed>>
     */
    private function details(array $account): array
    {
        $details = $account['details'] ?? [];
        if (!\is_array($details)) {
            return [];
        }

        return array_values(array_filter($details, \is_array(...)));
    }

    private function orderSide(mixed $side): OrderSide
    {
        return match (strtolower((string) $side)) {
            'buy' => OrderSide::BUY,
            'sell' => OrderSide::SELL,
            default => throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid'),
        };
    }

    /** @param array<string,mixed> $row */
    private function assertKnownEnums(array $row): void
    {
        $this->assertKnownEnum($row, 'side', ['buy', 'sell']);
        $this->assertKnownEnum($row, 'state', [
            'filled', 'partially_filled', 'canceled', 'cancelled', 'mmp_canceled', 'rejected', 'live',
            'effective', 'partially_effective', 'order_failed', 'partially_failed', 'pause',
        ]);
        $this->assertKnownEnum($row, 'ordType', [
            'limit', 'market', 'post_only', 'ioc', 'fok', 'optimal_limit_ioc',
            'conditional', 'trigger', 'oco', 'move_order_stop', 'iceberg', 'twap',
        ]);
        $this->assertKnownEnum($row, 'posSide', ['long', 'short', 'net']);
        $this->assertKnownEnum($row, 'tdMode', ['cross', 'isolated', 'cash', 'simulated']);
        $this->assertKnownEnum($row, 'mgnMode', ['cross', 'isolated', 'cash', 'simulated']);
        $this->assertKnownEnum($row, 'reduceOnly', ['true', 'false']);
    }

    /** @param list<string> $allowed */
    /**
     * @param array<string,mixed> $row
     * @param list<string> $allowed
     */
    private function assertKnownEnum(array $row, string $key, array $allowed): void
    {
        if (!array_key_exists($key, $row) || $row[$key] === null) {
            return;
        }

        $value = is_scalar($row[$key]) ? strtolower(trim((string) $row[$key])) : null;
        if ($value === '' && in_array($key, ['posSide', 'tdMode', 'mgnMode'], true)) {
            return;
        }
        if ($value === null || !in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid');
        }
    }

    private function exchangeOrderSide(mixed $side): ExchangeOrderSide
    {
        return strtolower((string) $side) === 'sell' ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY;
    }

    private function positionSide(mixed $side, float $size): ?PositionSide
    {
        return match (strtolower((string) $side)) {
            'long' => PositionSide::LONG,
            'short' => PositionSide::SHORT,
            default => $size < 0 ? PositionSide::SHORT : ($size > 0 ? PositionSide::LONG : null),
        };
    }

    private function exchangePositionSide(mixed $side): ?ExchangePositionSide
    {
        return match (strtolower((string) $side)) {
            'long' => ExchangePositionSide::LONG,
            'short' => ExchangePositionSide::SHORT,
            default => null,
        };
    }

    private function legacyOpenType(mixed $side, mixed $positionSide): ?int
    {
        return match (strtolower((string) $positionSide) . ':' . strtolower((string) $side)) {
            'long:buy' => 1,
            'long:sell' => 2,
            'short:buy' => 3,
            'short:sell' => 4,
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $row
     */
    private function orderType(array $row, bool $algo): OrderType
    {
        if ($algo) {
            return OrderType::STOP;
        }

        return match (strtolower($this->string($row['ordType'] ?? ''))) {
            'market' => OrderType::MARKET,
            'limit', 'post_only', 'ioc', 'fok', 'optimal_limit_ioc' => OrderType::LIMIT,
            default => throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid'),
        };
    }

    private function orderStatus(mixed $state): OrderStatus
    {
        return match (strtolower((string) $state)) {
            'filled' => OrderStatus::FILLED,
            'partially_filled' => OrderStatus::PARTIALLY_FILLED,
            'canceled', 'cancelled', 'mmp_canceled' => OrderStatus::CANCELLED,
            'rejected', 'order_failed', 'partially_failed' => OrderStatus::REJECTED,
            'effective' => OrderStatus::FILLED,
            'live', 'partially_effective', 'pause' => OrderStatus::PENDING,
            default => throw new \InvalidArgumentException('okx_private_rest_snapshot_value_invalid'),
        };
    }

    /**
     * @param array<string,mixed> $row
     */
    private function stopPrice(array $row, OrderType $orderType): ?BigDecimal
    {
        if ($orderType !== OrderType::STOP) {
            return null;
        }

        return $this->decimalOrNull($row['slTriggerPx'] ?? $row['tpTriggerPx'] ?? null);
    }

    private function decimalOrNull(mixed $value): ?BigDecimal
    {
        if (!$this->isUsableNumber($value)) {
            return null;
        }

        return BigDecimal::of((string) $value);
    }

    private function time(mixed $milliseconds): \DateTimeImmutable
    {
        if (is_numeric($milliseconds)) {
            return (new \DateTimeImmutable('@' . ((int) floor(((float) $milliseconds) / 1000))))->setTimezone(new \DateTimeZone('UTC'));
        }

        return new \DateTimeImmutable('@0');
    }

    private function hasTimestamp(mixed $value): bool
    {
        return is_numeric($value);
    }

    private function number(mixed $value): string
    {
        if (\is_float($value) || \is_int($value)) {
            return (string) $value;
        }

        return $this->isUsableNumber($value) ? (string) $value : '0';
    }

    private function firstNumber(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (\is_float($value) || \is_int($value) || $this->isUsableNumber($value)) {
                return (string) $value;
            }
        }

        return '0';
    }

    private function isUsableNumber(mixed $value): bool
    {
        return \is_scalar($value) && is_numeric((string) $value) && (string) $value !== '';
    }

    private function float(mixed $value): float
    {
        return $this->isUsableNumber($value) ? (float) $value : 0.0;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $this->isUsableNumber($value) ? (float) $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $this->isUsableNumber($value) ? (int) $value : null;
    }

    private function string(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }

    private function stringOrNull(mixed $value): ?string
    {
        $string = $this->string($value);

        return $string === '' ? null : $string;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function orderMetadata(array $row, bool $algo): array
    {
        $metadata = $this->redacted($row);
        $clientOrderId = $this->stringOrNull($row[$algo ? 'algoClOrdId' : 'clOrdId'] ?? null);
        if ($clientOrderId !== null) {
            $metadata['client_order_id'] = $clientOrderId;
        }

        return $metadata;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function redacted(array $payload): array
    {
        $redacted = [];
        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            if (str_contains($normalized, 'secret') || str_contains($normalized, 'apikey') || str_contains($normalized, 'passphrase')) {
                continue;
            }

            $redacted[$key] = \is_array($value) ? $this->redacted($value) : $value;
        }

        return $redacted;
    }
}
