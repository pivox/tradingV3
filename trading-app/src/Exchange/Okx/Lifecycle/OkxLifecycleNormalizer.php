<?php

declare(strict_types=1);

namespace App\Exchange\Okx\Lifecycle;

use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Okx\OkxActionFactory;
use App\Exchange\Okx\OkxFillId;
use App\Exchange\Okx\OkxInstrumentResolver;

final readonly class OkxLifecycleNormalizer
{
    private const SENSITIVE_KEY_PATTERN = '/(api[_-]?key|access[_-]?key|secret|private[_-]?key|passphrase|password|authorization|cookie|token|signature|sign|credentials?|memo)/i';

    public function __construct(
        private OkxInstrumentResolver $instruments = new OkxInstrumentResolver(),
        private OkxActionFactory $actions = new OkxActionFactory(),
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function normalizeOrderRequest(PlaceOrderRequest $request): array
    {
        $instId = $this->instruments->instId($request->symbol);

        return $this->actions->isTriggerOrder($request)
            ? $this->actions->algoOrder($instId, $request)
            : $this->actions->order($instId, $request);
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function normalizeOrderLifecycle(array $rows): OkxNormalizedOrderLifecycleDto
    {
        $deduplicated = $this->deduplicate($rows);
        usort(
            $deduplicated,
            fn (array $a, array $b): int => $this->rowTimeMillis($a) <=> $this->rowTimeMillis($b),
        );

        $latest = $deduplicated[array_key_last($deduplicated)] ?? [];
        $status = $this->statusFromRow($latest);
        $orderType = $this->orderType($latest);
        $quantity = $this->float($latest['sz'] ?? null);
        $filled = max($this->float($latest['accFillSz'] ?? null), $this->sumFillQuantity($deduplicated));
        if ($status === OkxLifecycleStatus::FILLED && $filled <= 0.00000001) {
            $filled = $quantity;
        }

        $fills = $this->fillsFromRows($deduplicated);
        $qualityFlags = [];
        if ($status === OkxLifecycleStatus::UNKNOWN_REQUIRES_RESYNC) {
            $qualityFlags[] = 'unknown_order_state';
        }
        if ($status === OkxLifecycleStatus::CANCELED && $filled > 0.0) {
            $qualityFlags[] = 'terminal_cancel_with_fill';
        }
        if (count($deduplicated) < count($rows)) {
            $qualityFlags[] = 'duplicate_event';
        }

        return new OkxNormalizedOrderLifecycleDto(
            status: $status,
            symbol: $this->symbol($latest),
            exchangeOrderId: $this->orderId($latest),
            clientOrderId: $this->clientOrderId($latest),
            side: $this->orderSide($latest['side'] ?? null),
            positionSide: $this->positionSide($latest['posSide'] ?? null),
            orderType: $orderType,
            quantity: $quantity,
            filledQuantity: $filled,
            remainingQuantity: max(0.0, $quantity - $filled),
            price: $this->orderPrice($latest, $orderType),
            averageFillPrice: $this->averageFillPrice($latest, $fills),
            createdAt: $this->time($latest['cTime'] ?? null),
            updatedAt: $this->time($latest['uTime'] ?? $latest['fillTime'] ?? null),
            fills: $fills,
            requiresResync: $status === OkxLifecycleStatus::UNKNOWN_REQUIRES_RESYNC,
            deduplicatedEventCount: count($deduplicated),
            qualityFlags: array_values(array_unique($qualityFlags)),
            redactedPayload: $this->redacted($latest),
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<OkxNormalizedFillDto>
     */
    public function normalizeFills(array $rows): array
    {
        return $this->fillsFromRows($this->deduplicate($rows));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<OkxNormalizedPositionDto>
     */
    public function normalizePositions(array $rows): array
    {
        $positions = [];
        foreach ($rows as $row) {
            if (!$this->isSwapRow($row)) {
                continue;
            }

            $side = $this->positionSide($row['posSide'] ?? null, $this->float($row['pos'] ?? null));
            if (!$side instanceof ExchangePositionSide) {
                continue;
            }

            $positions[] = new OkxNormalizedPositionDto(
                symbol: $this->symbol($row),
                side: $side,
                size: abs($this->float($row['pos'] ?? null)),
                entryPrice: $this->float($row['avgPx'] ?? null),
                markPrice: $this->floatOrNull($row['markPx'] ?? null),
                unrealizedPnl: $this->floatOrNull($row['upl'] ?? null),
                leverage: $this->floatOrNull($row['lever'] ?? null),
                updatedAt: $this->timeOrNull($row['uTime'] ?? null),
                redactedPayload: $this->redacted($row),
            );
        }

        return $positions;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function normalizeError(array $payload): OkxNormalizedErrorDto
    {
        $first = $this->firstDataRow($payload);
        $code = $this->firstNonEmpty($first['sCode'] ?? null, $payload['code'] ?? null);

        return new OkxNormalizedErrorDto(
            status: OkxLifecycleStatus::FAILED,
            code: $code,
            message: $this->firstNonEmpty($first['sMsg'] ?? null, $payload['msg'] ?? null),
            exchangeOrderId: $this->stringOrNull($this->orderId($first)),
            clientOrderId: $this->clientOrderId($first),
            qualityFlags: $code === '' ? ['missing_error_code'] : [],
            redactedPayload: $this->redacted($payload),
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function deduplicate(array $rows): array
    {
        $seen = [];
        $deduplicated = [];
        foreach ($rows as $row) {
            $key = implode('|', [
                $this->orderId($row),
                $this->string($row['state'] ?? ''),
                $this->string($row['uTime'] ?? $row['fillTime'] ?? ''),
                $this->string($row['accFillSz'] ?? ''),
                $this->string($row['tradeId'] ?? ''),
            ]);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduplicated[] = $row;
        }

        return $deduplicated;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<OkxNormalizedFillDto>
     */
    private function fillsFromRows(array $rows): array
    {
        $fills = [];
        $seen = [];
        foreach ($rows as $row) {
            $fill = $this->fillFromRow($row);
            if (!$fill instanceof OkxNormalizedFillDto || isset($seen[$fill->fillId])) {
                continue;
            }

            $seen[$fill->fillId] = true;
            $fills[] = $fill;
        }

        return $fills;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function fillFromRow(array $row): ?OkxNormalizedFillDto
    {
        $quantity = $this->float($row['fillSz'] ?? null);
        $price = $this->float($row['fillPx'] ?? null);
        $fillId = OkxFillId::fromTradeId($row['instId'] ?? '', $row['tradeId'] ?? null);
        if ($fillId === null || $quantity <= 0.0 || $price <= 0.0) {
            return null;
        }

        return new OkxNormalizedFillDto(
            symbol: $this->symbol($row),
            exchangeOrderId: $this->orderId($row),
            clientOrderId: $this->clientOrderId($row),
            fillId: $fillId,
            side: $this->orderSide($row['side'] ?? null),
            positionSide: $this->positionSide($row['posSide'] ?? null),
            quantity: $quantity,
            price: $price,
            fee: $this->floatOrNull($this->firstNonEmpty($row['fillFee'] ?? null, $row['fee'] ?? null)),
            feeCurrency: $this->stringOrNull($this->firstNonEmpty($row['fillFeeCcy'] ?? null, $row['feeCcy'] ?? null)),
            occurredAt: $this->time($row['fillTime'] ?? $row['ts'] ?? $row['uTime'] ?? null),
            redactedPayload: $this->redacted($row),
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param list<OkxNormalizedFillDto> $fills
     */
    private function averageFillPrice(array $row, array $fills): ?float
    {
        $average = $this->floatOrNull($row['avgPx'] ?? null);
        if ($average !== null && $average > 0.0) {
            return $average;
        }

        $quantity = 0.0;
        $notional = 0.0;
        foreach ($fills as $fill) {
            $quantity += $fill->quantity;
            $notional += $fill->quantity * $fill->price;
        }

        return $quantity > 0.0 ? $notional / $quantity : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function statusFromRow(array $row): OkxLifecycleStatus
    {
        if ($this->hasValue($row['state'] ?? null)) {
            return $this->status($row['state']);
        }

        $code = $this->firstNonEmpty($row['sCode'] ?? null, $row['code'] ?? null);
        if ($code === '0' && ($this->orderId($row) !== ''
            || $this->hasValue($row['clOrdId'] ?? null)
            || $this->hasValue($row['algoClOrdId'] ?? null)
        )) {
            return OkxLifecycleStatus::ACCEPTED;
        }
        if ($code !== '') {
            return OkxLifecycleStatus::FAILED;
        }

        return $this->status(null);
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function sumFillQuantity(array $rows): float
    {
        $quantity = 0.0;
        foreach ($this->fillsFromRows($rows) as $fill) {
            $quantity += $fill->quantity;
        }

        return $quantity;
    }

    private function status(mixed $state): OkxLifecycleStatus
    {
        return match (strtolower((string) $state)) {
            'pending' => OkxLifecycleStatus::PENDING,
            'accepted' => OkxLifecycleStatus::ACCEPTED,
            'live' => OkxLifecycleStatus::OPEN,
            'partially_filled' => OkxLifecycleStatus::PARTIALLY_FILLED,
            'filled' => OkxLifecycleStatus::FILLED,
            'canceling', 'cancelling' => OkxLifecycleStatus::CANCEL_PENDING,
            'canceled', 'cancelled', 'mmp_canceled' => OkxLifecycleStatus::CANCELED,
            'rejected' => OkxLifecycleStatus::REJECTED,
            'expired' => OkxLifecycleStatus::EXPIRED,
            'order_failed', 'partially_failed', 'failed' => OkxLifecycleStatus::FAILED,
            default => OkxLifecycleStatus::UNKNOWN_REQUIRES_RESYNC,
        };
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
        if (\in_array(strtolower($this->string($row['ordType'] ?? '')), ['conditional', 'trigger'], true)) {
            return ExchangeOrderType::TRIGGER;
        }

        return \in_array(strtolower($this->string($row['ordType'] ?? '')), ['market', 'optimal_limit_ioc'], true)
            ? ExchangeOrderType::MARKET
            : ExchangeOrderType::LIMIT;
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

        foreach ([$row['px'] ?? null, $row['ordPx'] ?? null] as $fallback) {
            $price = $this->floatOrNull($fallback);
            if ($price !== null && $price > 0.0) {
                return $price;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function orderId(array $row): string
    {
        if ($this->hasValue($row['algoId'] ?? null)) {
            return 'algo:' . $this->string($row['algoId']);
        }
        if ($this->hasValue($row['ordId'] ?? null)) {
            return $this->string($row['ordId']);
        }

        return '';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function clientOrderId(array $row): ?string
    {
        return $this->stringOrNull($this->firstNonEmpty($row['clOrdId'] ?? null, $row['algoClOrdId'] ?? null));
    }

    private function orderSide(mixed $side): ExchangeOrderSide
    {
        return strtolower((string) $side) === 'sell' ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY;
    }

    private function positionSide(mixed $side, ?float $size = null): ?ExchangePositionSide
    {
        return match (strtolower((string) $side)) {
            'long' => ExchangePositionSide::LONG,
            'short' => ExchangePositionSide::SHORT,
            default => $size !== null && $size < -0.00000001
                ? ExchangePositionSide::SHORT
                : ($size !== null && $size > 0.00000001 ? ExchangePositionSide::LONG : null),
        };
    }

    /**
     * @param array<string,mixed> $row
     */
    private function symbol(array $row): string
    {
        return $this->instruments->symbol($this->string($row['instId'] ?? ''));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isSwapRow(array $row): bool
    {
        return strtoupper($this->string($row['instType'] ?? 'SWAP')) === 'SWAP'
            && str_ends_with(strtoupper($this->string($row['instId'] ?? '')), '-SWAP');
    }

    /**
     * @param array<string,mixed> $row
     */
    private function rowTimeMillis(array $row): int
    {
        foreach (['uTime', 'fillTime', 'ts', 'cTime'] as $key) {
            if (is_numeric($row[$key] ?? null)) {
                return (int) floor((float) $row[$key]);
            }
        }

        return 0;
    }

    private function time(mixed $milliseconds): \DateTimeImmutable
    {
        return $this->timeOrNull($milliseconds) ?? new \DateTimeImmutable('@0', new \DateTimeZone('UTC'));
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

    private function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
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

    private function firstNonEmpty(mixed ...$values): string
    {
        foreach ($values as $value) {
            $string = $this->string($value);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private function hasValue(mixed $value): bool
    {
        return $this->string($value) !== '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function firstDataRow(array $payload): array
    {
        $data = $payload['data'] ?? [];
        if (!\is_array($data) || !\is_array($data[0] ?? null)) {
            return [];
        }

        /** @var array<string,mixed> $row */
        $row = $data[0];

        return $row;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function redacted(array $payload): array
    {
        $redacted = [];
        foreach ($payload as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY_PATTERN, (string) $key) === 1) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }

            $redacted[$key] = \is_array($value) ? $this->redacted($value) : $value;
        }

        return $redacted;
    }
}
