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
                availableBalance: BigDecimal::of($this->number($detail['availEq'] ?? $detail['availBal'] ?? '0')),
                frozenBalance: BigDecimal::of($this->number($detail['frozenBal'] ?? $detail['ordFrozen'] ?? '0')),
                unrealized: BigDecimal::of($this->number($detail['upl'] ?? '0')),
                equity: BigDecimal::of($this->number($detail['eq'] ?? $account['totalEq'] ?? '0')),
                positionDeposit: BigDecimal::of($this->number($detail['imr'] ?? $detail['margin'] ?? '0')),
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
        return strtolower((string) $side) === 'sell' ? OrderSide::SELL : OrderSide::BUY;
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

    /**
     * @param array<string,mixed> $row
     */
    private function orderType(array $row, bool $algo): OrderType
    {
        if ($algo) {
            return OrderType::STOP;
        }

        return strtolower($this->string($row['ordType'] ?? '')) === 'market'
            ? OrderType::MARKET
            : OrderType::LIMIT;
    }

    private function orderStatus(mixed $state): OrderStatus
    {
        return match (strtolower((string) $state)) {
            'filled' => OrderStatus::FILLED,
            'partially_filled' => OrderStatus::PARTIALLY_FILLED,
            'canceled', 'cancelled' => OrderStatus::CANCELLED,
            'rejected' => OrderStatus::REJECTED,
            default => OrderStatus::PENDING,
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
