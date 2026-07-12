<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

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
use Brick\Math\BigDecimal;

final readonly class HyperliquidPrivateReadMapper
{
    /**
     * @param array<string,mixed> $state
     */
    public function account(array $state): ?AccountDto
    {
        $margin = $this->array($state['marginSummary'] ?? []);
        if ($margin === [] && !array_key_exists('withdrawable', $state)) {
            return null;
        }

        return new AccountDto(
            currency: 'USDC',
            availableBalance: BigDecimal::of($this->firstNumber($state['withdrawable'] ?? null, $margin['totalRawUsd'] ?? null, '0')),
            frozenBalance: BigDecimal::of('0'),
            unrealized: BigDecimal::of($this->number($this->unrealizedPnl($state))),
            equity: BigDecimal::of($this->firstNumber($margin['accountValue'] ?? null, '0')),
            positionDeposit: BigDecimal::of($this->firstNumber($margin['totalMarginUsed'] ?? null, $state['crossMarginSummary']['totalMarginUsed'] ?? null, '0')),
            metadata: ['source' => 'hyperliquid_clearinghouse_state'] + $this->redacted($margin),
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    public function position(array $row): ?PositionDto
    {
        $position = $this->array($row['position'] ?? $row);
        $size = $this->float($position['szi'] ?? null);
        if (abs($size) <= 0.00000001) {
            return null;
        }
        $marginMode = $position['leverage']['type'] ?? null;
        if (!is_string($marginMode) || !in_array($marginMode, ['isolated', 'cross'], true)) {
            throw new \InvalidArgumentException('hyperliquid_position_margin_mode_invalid');
        }

        return new PositionDto(
            symbol: $this->symbol($position['coin'] ?? ''),
            side: $size < 0 ? PositionSide::SHORT : PositionSide::LONG,
            size: BigDecimal::of($this->number(abs($size))),
            entryPrice: BigDecimal::of($this->number($position['entryPx'] ?? '0')),
            markPrice: BigDecimal::of($this->number($position['markPx'] ?? '0')),
            unrealizedPnl: BigDecimal::of($this->number($position['unrealizedPnl'] ?? '0')),
            realizedPnl: BigDecimal::of('0'),
            margin: BigDecimal::of($this->number($position['marginUsed'] ?? '0')),
            leverage: BigDecimal::of($this->number($position['leverage']['value'] ?? '1')),
            openedAt: $this->time($position['openedAt'] ?? null),
            metadata: ['margin_mode' => $marginMode] + $this->redacted($position),
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    public function order(array $row): OrderDto
    {
        $quantity = BigDecimal::of($this->number($row['origSz'] ?? $row['sz'] ?? '0'));
        $remaining = BigDecimal::of($this->number($row['sz'] ?? '0'));
        $filled = $quantity->minus($remaining);
        if ($filled->isNegative()) {
            $filled = BigDecimal::zero();
        }

        return new OrderDto(
            orderId: $this->string($row['oid'] ?? ''),
            symbol: $this->symbol($row['coin'] ?? ''),
            side: $this->orderSide($row['side'] ?? null),
            type: $this->orderType($row),
            status: OrderStatus::PENDING,
            quantity: $quantity,
            price: $this->decimalOrNull($row['limitPx'] ?? $row['px'] ?? null),
            stopPrice: $this->decimalOrNull($row['triggerPx'] ?? null),
            filledQuantity: $filled,
            remainingQuantity: $remaining,
            averagePrice: null,
            createdAt: $this->time($row['timestamp'] ?? null),
            updatedAt: null,
            metadata: $this->orderMetadata($row),
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    public function fill(array $row): ExchangeFillDto
    {
        return new ExchangeFillDto(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            symbol: $this->symbol($row['coin'] ?? ''),
            exchangeOrderId: $this->string($row['oid'] ?? ''),
            clientOrderId: $this->stringOrNull($row['cloid'] ?? null),
            fillId: $this->stringOrNull($row['hash'] ?? null),
            side: $this->exchangeOrderSide($row['side'] ?? null),
            positionSide: null,
            quantity: $this->float($row['sz'] ?? null),
            price: $this->float($row['px'] ?? null),
            fee: $this->floatOrNull($row['fee'] ?? null),
            feeCurrency: 'USDC',
            filledAt: $this->time($row['time'] ?? null),
            metadata: $this->redacted($row),
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function legacyTrade(array $row): array
    {
        $trade = [
            'exchange' => 'hyperliquid',
            'symbol' => $this->symbol($row['coin'] ?? ''),
            'order_id' => $this->string($row['oid'] ?? ''),
            'client_order_id' => $this->stringOrNull($row['cloid'] ?? null),
            'trade_id' => $this->string($row['hash'] ?? ''),
            'side' => $this->exchangeOrderSide($row['side'] ?? null)->value,
            'position_side' => null,
            'open_type' => null,
            'size' => $this->number($row['sz'] ?? '0'),
            'price' => $this->number($row['px'] ?? '0'),
            'fee_currency' => 'USDC',
            'create_time' => is_numeric($row['time'] ?? null) ? (int) $row['time'] : null,
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
    public function legacyFunding(array $row, ?int $requestedFlowType): array
    {
        $delta = $this->array($row['delta'] ?? []);

        return [
            'exchange' => 'hyperliquid',
            'symbol' => $this->symbol($row['coin'] ?? $delta['coin'] ?? ''),
            'flow_type' => $requestedFlowType ?? 3,
            'amount' => $this->firstNumber(
                $row['usdc'] ?? null,
                $delta['usdc'] ?? null,
                $row['funding'] ?? null,
                $delta['funding'] ?? null,
                '0',
            ),
            'currency' => 'USDC',
            'create_time' => is_numeric($row['time'] ?? null) ? (int) $row['time'] : null,
            'raw_reference' => $this->redacted($row),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function tradingFees(array $payload, string $symbol, string $coin): array
    {
        $maker = $this->numberOrNull($payload['userAddRate'] ?? null);
        $taker = $this->numberOrNull($payload['userCrossRate'] ?? null);
        $qualityFlags = [];

        if ($maker === null) {
            $qualityFlags[] = 'maker_fee_unknown';
        }
        if ($taker === null) {
            $qualityFlags[] = 'taker_fee_unknown';
        }
        if (!\is_array($payload['feeSchedule'] ?? null)) {
            $qualityFlags[] = 'fee_schedule_unknown';
        }

        return [
            'exchange' => 'hyperliquid',
            'symbol' => strtoupper($symbol),
            'coin' => strtoupper($coin),
            'fee_currency' => 'USDC',
            'maker' => $maker,
            'taker' => $taker,
            'quality_flags' => array_values(array_unique($qualityFlags)),
            'raw_reference' => $this->redacted($payload),
        ];
    }

    public function symbol(mixed $coin): string
    {
        $coin = strtoupper($this->string($coin));
        if ($coin === '') {
            return '';
        }

        return $coin . 'USDT';
    }

    public function time(mixed $milliseconds): \DateTimeImmutable
    {
        if (is_numeric($milliseconds)) {
            return (new \DateTimeImmutable('@' . ((int) floor(((float) $milliseconds) / 1000))))->setTimezone(new \DateTimeZone('UTC'));
        }

        return new \DateTimeImmutable('@0', new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string,mixed> $state
     */
    private function unrealizedPnl(array $state): string
    {
        $total = 0.0;
        $positions = $state['assetPositions'] ?? [];
        if (!\is_array($positions)) {
            return '0';
        }

        foreach ($positions as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $position = $this->array($row['position'] ?? []);
            $total += $this->float($position['unrealizedPnl'] ?? null);
        }

        return $this->number($total);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function orderMetadata(array $row): array
    {
        $metadata = $this->redacted($row);
        $clientOrderId = $this->stringOrNull($row['cloid'] ?? null);
        if ($clientOrderId !== null) {
            $metadata['client_order_id'] = $clientOrderId;
        }

        return $metadata;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function orderType(array $row): OrderType
    {
        $type = strtolower($this->string($row['orderType'] ?? $row['type'] ?? ''));
        if (str_contains($type, 'trigger') || array_key_exists('triggerPx', $row)) {
            return OrderType::STOP;
        }

        return $type === 'market' ? OrderType::MARKET : OrderType::LIMIT;
    }

    private function orderSide(mixed $side): OrderSide
    {
        return $this->exchangeOrderSide($side) === ExchangeOrderSide::SELL ? OrderSide::SELL : OrderSide::BUY;
    }

    private function exchangeOrderSide(mixed $side): ExchangeOrderSide
    {
        $normalized = strtolower($this->string($side));

        return in_array($normalized, ['a', 'ask', 'sell', 's'], true)
            ? ExchangeOrderSide::SELL
            : ExchangeOrderSide::BUY;
    }

    private function decimalOrNull(mixed $value): ?BigDecimal
    {
        if (!$this->isUsableNumber($value)) {
            return null;
        }

        return BigDecimal::of((string) $value);
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

    private function numberOrNull(mixed $value): ?string
    {
        return $this->isUsableNumber($value) ? (string) $value : null;
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
     * @return array<string,mixed>
     */
    private function array(mixed $value): array
    {
        return \is_array($value) ? $value : [];
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
            if (
                str_contains($normalized, 'secret')
                || str_contains($normalized, 'apikey')
                || str_contains($normalized, 'privatekey')
                || str_contains($normalized, 'passphrase')
                || str_contains($normalized, 'signature')
            ) {
                continue;
            }

            $redacted[$key] = \is_array($value) ? $this->redacted($value) : $value;
        }

        return $redacted;
    }
}
