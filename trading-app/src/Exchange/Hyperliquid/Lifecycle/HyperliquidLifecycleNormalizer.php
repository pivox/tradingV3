<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid\Lifecycle;

use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Hyperliquid\HyperliquidActionFactory;

final readonly class HyperliquidLifecycleNormalizer
{
    private const SENSITIVE_KEY_PATTERN = '/(api[_-]?key|access[_-]?key|secret|private[_-]?key|passphrase|password|authorization|cookie|token|signature|sign|credentials?|memo)/i';

    public function __construct(
        private HyperliquidActionFactory $actions = new HyperliquidActionFactory(),
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function normalizeOrderRequest(int $assetId, PlaceOrderRequest $request): array
    {
        return $this->actions->order($assetId, $request);
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function normalizeOrderLifecycle(array $rows): HyperliquidNormalizedOrderLifecycleDto
    {
        $normalizedRows = array_map(fn (array $row): array => $this->normalizeLifecycleRow($row), $rows);
        $supportedRows = array_values(array_filter($normalizedRows, fn (array $row): bool => $this->isSupportedLifecycleRow($row)));
        $deduplicated = $this->sortLifecycleRows($this->deduplicate($supportedRows));
        $fills = $this->fillsFromRows($deduplicated);
        $latest = $deduplicated[array_key_last($deduplicated)] ?? [];
        $latestOrder = $this->latestOrderRow($deduplicated);
        $base = $latestOrder === [] ? $latest : $latestOrder;

        $quantity = $this->orderQuantity($base, $fills);
        $remaining = $this->remainingQuantity($base, $quantity);
        $filled = max($this->sumFillQuantity($fills), max(0.0, $quantity - $remaining));
        if ($this->isFillRow($latest) && $this->rowTimeMillis($latest) > $this->rowTimeMillis($base)) {
            $remaining = max(0.0, $quantity - $filled);
        }
        $status = $this->statusFromRows($base, $latestOrder !== [], $quantity, $filled, $remaining, $fills);
        if ($status === HyperliquidLifecycleStatus::FILLED && $filled <= 0.00000001 && $quantity > 0.0) {
            $filled = $quantity;
            $remaining = 0.0;
        }

        $qualityFlags = [];
        if ($latestOrder === [] && $fills !== []) {
            $qualityFlags[] = 'order_absent_fill_present';
        }
        if ($status === HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC) {
            $qualityFlags[] = 'unknown_order_state';
        }
        if (count($deduplicated) < count($supportedRows)) {
            $qualityFlags[] = 'duplicate_event';
        }
        if (count($supportedRows) < count($rows)) {
            $qualityFlags[] = 'unsupported_lifecycle_row_ignored';
        }

        return new HyperliquidNormalizedOrderLifecycleDto(
            status: $status,
            symbol: $this->symbol($base),
            exchangeOrderId: $this->orderId($base),
            clientOrderId: $this->clientOrderId($base),
            side: $this->orderSideOrNull($base['side'] ?? null),
            positionSide: $this->positionSide(null, null, $base['side'] ?? null, $this->bool($base['reduceOnly'] ?? false)),
            orderType: $this->orderType($base),
            quantity: $quantity,
            filledQuantity: $filled,
            remainingQuantity: max(0.0, $remaining),
            price: $this->orderPrice($base),
            averageFillPrice: $this->averageFillPrice($base, $fills),
            createdAt: $this->time($base['timestamp'] ?? $base['time'] ?? null),
            updatedAt: $this->time($latest['uTime'] ?? $latest['updatedAt'] ?? $latest['time'] ?? $latest['timestamp'] ?? $base['uTime'] ?? $base['updatedAt'] ?? $base['time'] ?? null),
            fills: $fills,
            requiresResync: \in_array('order_absent_fill_present', $qualityFlags, true)
                || $status === HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC,
            deduplicatedEventCount: count($deduplicated),
            qualityFlags: array_values(array_unique($qualityFlags)),
            redactedPayload: $this->redacted($base),
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<HyperliquidNormalizedFillDto>
     */
    public function normalizeFills(array $rows): array
    {
        return $this->fillsFromRows($this->deduplicate($rows));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<HyperliquidNormalizedPositionDto>
     */
    public function normalizePositions(array $rows): array
    {
        $positions = [];
        foreach ($rows as $row) {
            $position = \is_array($row['position'] ?? null) ? $row['position'] : $row;
            /** @var array<string,mixed> $position */
            if (!$this->hasValue($position['coin'] ?? null)) {
                continue;
            }

            $size = $this->float($position['szi'] ?? $position['position'] ?? $position['size'] ?? null);
            $flags = [];
            if (abs($size) <= 0.00000001) {
                $flags[] = 'position_closed_zero_size';
            }

            $positions[] = new HyperliquidNormalizedPositionDto(
                symbol: $this->symbol($position),
                side: $size < -0.00000001 ? ExchangePositionSide::SHORT : ExchangePositionSide::LONG,
                size: abs($size),
                entryPrice: $this->float($position['entryPx'] ?? null),
                markPrice: $this->floatOrNull($position['markPx'] ?? null),
                unrealizedPnl: $this->floatOrNull($position['unrealizedPnl'] ?? null),
                marginUsed: $this->floatOrNull($position['marginUsed'] ?? null),
                leverage: $this->leverage($position),
                updatedAt: $this->timeOrNull($position['time'] ?? $position['uTime'] ?? null),
                qualityFlags: $flags,
                redactedPayload: $this->redacted($position),
            );
        }

        return $positions;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<HyperliquidNormalizedFundingDto>
     */
    public function normalizeFunding(array $rows): array
    {
        $funding = [];
        foreach ($rows as $row) {
            $delta = \is_array($row['delta'] ?? null) ? $row['delta'] : $row;
            /** @var array<string,mixed> $delta */
            if (!$this->hasValue($delta['coin'] ?? null)) {
                continue;
            }

            $amount = $this->floatOrNull($delta['usdc'] ?? $delta['funding'] ?? $delta['amount'] ?? null);
            if ($amount === null) {
                continue;
            }

            $funding[] = new HyperliquidNormalizedFundingDto(
                symbol: $this->symbol($delta),
                amount: $amount,
                currency: 'USDC',
                role: 'funding',
                fundingRate: $this->floatOrNull($delta['fundingRate'] ?? null),
                occurredAt: $this->time($row['time'] ?? $delta['time'] ?? null),
                redactedPayload: $this->redacted($row),
            );
        }

        return $funding;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function normalizeError(array $payload): HyperliquidNormalizedErrorDto
    {
        $data = $this->errorDataRow($payload);
        $message = $this->firstNonEmpty($data['error'] ?? null, $data['message'] ?? null, $payload['error'] ?? null, $payload['message'] ?? null);
        $lower = strtolower($message);
        $code = match (true) {
            str_contains($lower, 'insufficient')
                || str_contains($lower, 'margin')
                || str_contains($lower, 'collateral') => 'insufficient_collateral',
            str_contains($lower, 'market')
                || str_contains($lower, 'not open')
                || str_contains($lower, 'unavailable')
                || str_contains($lower, 'delist') => 'market_unavailable',
            default => $this->firstNonEmpty($data['code'] ?? null, $payload['code'] ?? null, 'exchange_error'),
        };

        return new HyperliquidNormalizedErrorDto(
            status: HyperliquidLifecycleStatus::FAILED,
            code: $code,
            message: $message,
            exchangeOrderId: $this->stringOrNull($this->firstNonEmpty($data['oid'] ?? null, $payload['oid'] ?? null)),
            clientOrderId: $this->stringOrNull($this->firstNonEmpty($data['cloid'] ?? null, $payload['cloid'] ?? null)),
            qualityFlags: $message === '' ? ['missing_error_message'] : [],
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
                $this->clientOrderId($row) ?? '',
                $this->string($row['status'] ?? $row['state'] ?? ''),
                $this->string($row['uTime'] ?? $row['updatedAt'] ?? $row['time'] ?? ''),
                $this->string($row['tid'] ?? $row['fillId'] ?? $row['exchangeFillId'] ?? $row['hash'] ?? ''),
                $this->string($row['sz'] ?? ''),
                $this->string($row['px'] ?? ''),
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
     * @return list<array<string,mixed>>
     */
    private function sortLifecycleRows(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $index => $row) {
            $indexed[] = ['index' => $index, 'row' => $row];
        }

        usort(
            $indexed,
            function (array $left, array $right): int {
                /** @var array<string,mixed> $leftRow */
                $leftRow = $left['row'];
                /** @var array<string,mixed> $rightRow */
                $rightRow = $right['row'];

                return [$this->rowTimeMillis($leftRow), $this->stateRank($leftRow), $left['index']]
                    <=> [$this->rowTimeMillis($rightRow), $this->stateRank($rightRow), $right['index']];
            },
        );

        $sorted = [];
        foreach ($indexed as $entry) {
            /** @var array<string,mixed> $row */
            $row = $entry['row'];
            $sorted[] = $row;
        }

        return $sorted;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<HyperliquidNormalizedFillDto>
     */
    private function fillsFromRows(array $rows): array
    {
        $fills = [];
        $seen = [];
        foreach ($rows as $row) {
            $fill = $this->fillFromRow($row);
            if (!$fill instanceof HyperliquidNormalizedFillDto || isset($seen[$fill->fillId])) {
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
    private function fillFromRow(array $row): ?HyperliquidNormalizedFillDto
    {
        if (!$this->isFillRow($row)) {
            return null;
        }

        $quantity = $this->float($row['sz'] ?? $row['quantity'] ?? null);
        $price = $this->float($row['px'] ?? $row['price'] ?? null);
        $fillId = $this->fillId($row);
        if ($fillId === null || $quantity <= 0.0 || $price <= 0.0) {
            return null;
        }

        return new HyperliquidNormalizedFillDto(
            symbol: $this->symbol($row),
            exchangeOrderId: $this->orderId($row),
            clientOrderId: $this->clientOrderId($row),
            fillId: $fillId,
            side: $this->orderSide($row['side'] ?? null),
            positionSide: $this->fillPositionSide($row),
            quantity: $quantity,
            price: $price,
            fee: $this->floatOrNull($row['fee'] ?? null),
            feeCurrency: $this->hasValue($row['feeToken'] ?? null) ? $this->string($row['feeToken']) : 'USDC',
            occurredAt: $this->time($row['time'] ?? $row['timestamp'] ?? null),
            redactedPayload: $this->redacted($row),
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param list<HyperliquidNormalizedFillDto> $fills
     */
    private function averageFillPrice(array $row, array $fills): ?float
    {
        $average = $this->floatOrNull($row['avgPx'] ?? $row['avgPrice'] ?? null);
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
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function latestOrderRow(array $rows): array
    {
        $latest = [];
        foreach ($rows as $row) {
            if (!$this->isFillRow($row)) {
                $latest = $row;
            }
        }

        return $latest;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<HyperliquidNormalizedFillDto> $fills
     */
    private function orderQuantity(array $row, array $fills): float
    {
        $quantity = $this->float($row['origSz'] ?? $row['originalSz'] ?? $row['orderSz'] ?? null);
        if ($quantity > 0.0) {
            return $quantity;
        }

        $rowQuantity = $this->float($row['sz'] ?? null);
        if ($rowQuantity > 0.0 && !$this->isFillRow($row)) {
            return $rowQuantity;
        }

        return $this->sumFillQuantity($fills);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function remainingQuantity(array $row, float $quantity): float
    {
        if ($this->hasValue($row['origSz'] ?? null) || $this->hasValue($row['originalSz'] ?? null)) {
            return $this->float($row['sz'] ?? $row['remainingSz'] ?? null);
        }

        $remaining = $this->floatOrNull($row['remainingSz'] ?? $row['remaining'] ?? null);

        return $remaining ?? $quantity;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<HyperliquidNormalizedFillDto> $fills
     */
    private function statusFromRows(
        array $row,
        bool $hasOrderRow,
        float $quantity,
        float $filled,
        float $remaining,
        array $fills,
    ): HyperliquidLifecycleStatus {
        if (!$hasOrderRow && $fills !== []) {
            return HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC;
        }
        if (!$this->hasValue($row['status'] ?? $row['state'] ?? null) && $hasOrderRow) {
            if ($quantity > 0.0 && $filled >= $quantity - 0.00000001) {
                return HyperliquidLifecycleStatus::FILLED;
            }
            if ($filled > 0.00000001 && $remaining > 0.00000001) {
                return HyperliquidLifecycleStatus::PARTIALLY_FILLED;
            }

            return HyperliquidLifecycleStatus::OPEN;
        }

        $status = $this->status($row['status'] ?? $row['state'] ?? null);
        if ($status === HyperliquidLifecycleStatus::OPEN && $filled > 0.00000001 && $remaining > 0.00000001) {
            return HyperliquidLifecycleStatus::PARTIALLY_FILLED;
        }
        if ($quantity > 0.0 && $filled >= $quantity - 0.00000001) {
            return HyperliquidLifecycleStatus::FILLED;
        }

        return $status;
    }

    private function status(mixed $status): HyperliquidLifecycleStatus
    {
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', (string) $status));
        if (str_contains($normalized, 'rejected')) {
            return HyperliquidLifecycleStatus::REJECTED;
        }
        if (str_contains($normalized, 'cancel')) {
            return HyperliquidLifecycleStatus::CANCELED;
        }

        return match ($normalized) {
            'accepted', 'resting', 'placed' => HyperliquidLifecycleStatus::ACCEPTED,
            'open', 'triggered' => HyperliquidLifecycleStatus::OPEN,
            'partial', 'partiallyfilled' => HyperliquidLifecycleStatus::PARTIALLY_FILLED,
            'filled', 'closed' => HyperliquidLifecycleStatus::FILLED,
            'failed', 'err', 'error' => HyperliquidLifecycleStatus::FAILED,
            default => HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC,
        };
    }

    /**
     * @param array<string,mixed> $row
     */
    private function stateRank(array $row): int
    {
        if ($this->isFillRow($row)) {
            return 45;
        }

        return match ($this->status($row['status'] ?? $row['state'] ?? null)) {
            HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC => 0,
            HyperliquidLifecycleStatus::ACCEPTED => 20,
            HyperliquidLifecycleStatus::OPEN => 30,
            HyperliquidLifecycleStatus::PARTIALLY_FILLED => 40,
            HyperliquidLifecycleStatus::CANCELED,
            HyperliquidLifecycleStatus::REJECTED,
            HyperliquidLifecycleStatus::FAILED => 60,
            HyperliquidLifecycleStatus::FILLED => 70,
        };
    }

    /**
     * @param array<string,mixed> $row
     */
    private function orderType(array $row): ExchangeOrderType
    {
        $type = strtolower($this->string($row['orderType'] ?? $row['type'] ?? ''));
        if ($this->bool($row['isTrigger'] ?? false) || $this->float($row['triggerPx'] ?? null) > 0.0) {
            if (str_contains($type, 'take') || str_contains($type, 'profit') || str_contains($type, 'tp')) {
                return ExchangeOrderType::TAKE_PROFIT;
            }
            if (str_contains($type, 'stop') || str_contains($type, 'sl')) {
                return ExchangeOrderType::STOP_LOSS;
            }

            return ExchangeOrderType::TRIGGER;
        }
        if (str_contains($type, 'take') || str_contains($type, 'profit')) {
            return ExchangeOrderType::TAKE_PROFIT;
        }
        if (str_contains($type, 'stop')) {
            return ExchangeOrderType::STOP_LOSS;
        }
        if (str_contains($type, 'market')) {
            return ExchangeOrderType::MARKET;
        }
        if (str_contains($type, 'trigger')) {
            return ExchangeOrderType::TRIGGER;
        }

        return ExchangeOrderType::LIMIT;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function orderPrice(array $row): ?float
    {
        foreach ([$row['limitPx'] ?? null, $row['px'] ?? null, $row['price'] ?? null, $row['avgPx'] ?? null] as $candidate) {
            $price = $this->floatOrNull($candidate);
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
        return $this->firstNonEmpty($row['oid'] ?? null, $row['orderId'] ?? null, $row['exchangeOrderId'] ?? null);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function clientOrderId(array $row): ?string
    {
        return $this->stringOrNull($this->firstNonEmpty($row['cloid'] ?? null, $row['clientOrderId'] ?? null));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function fillId(array $row): ?string
    {
        $explicit = $this->firstNonEmpty($row['tid'] ?? null, $row['fillId'] ?? null, $row['exchangeFillId'] ?? null, $row['hash'] ?? null);
        if ($explicit !== '') {
            return $explicit;
        }

        $fallback = implode('|', [
            $this->orderId($row),
            $this->string($row['time'] ?? $row['timestamp'] ?? ''),
            $this->string($row['sz'] ?? ''),
            $this->string($row['px'] ?? ''),
        ]);

        return trim($fallback, '|') === '' ? null : 'hl:' . substr(hash('sha256', $fallback), 0, 24);
    }

    private function orderSide(mixed $side): ExchangeOrderSide
    {
        $value = strtolower((string) $side);

        return \in_array($value, ['a', 'ask', 'sell', 's'], true) ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY;
    }

    private function orderSideOrNull(mixed $side): ?ExchangeOrderSide
    {
        return $this->hasValue($side) ? $this->orderSide($side) : null;
    }

    private function positionSide(
        mixed $side,
        ?float $size = null,
        mixed $orderSide = null,
        bool $reduceOnly = false,
    ): ?ExchangePositionSide {
        $positionSide = strtolower((string) $side);
        if ($positionSide === 'long') {
            return ExchangePositionSide::LONG;
        }
        if ($positionSide === 'short') {
            return ExchangePositionSide::SHORT;
        }
        if ($reduceOnly) {
            return $this->orderSide($orderSide) === ExchangeOrderSide::SELL
                ? ExchangePositionSide::LONG
                : ExchangePositionSide::SHORT;
        }
        if ($size !== null && $size < -0.00000001) {
            return ExchangePositionSide::SHORT;
        }
        if ($size !== null && $size > 0.00000001) {
            return ExchangePositionSide::LONG;
        }

        return $this->hasValue($orderSide)
            ? ($this->orderSide($orderSide) === ExchangeOrderSide::BUY ? ExchangePositionSide::LONG : ExchangePositionSide::SHORT)
            : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function fillPositionSide(array $row): ?ExchangePositionSide
    {
        $direction = strtolower($this->string($row['dir'] ?? ''));
        if (str_contains($direction, 'long')) {
            return ExchangePositionSide::LONG;
        }
        if (str_contains($direction, 'short')) {
            return ExchangePositionSide::SHORT;
        }

        return $this->positionSide(null, null, $row['side'] ?? null, $this->bool($row['reduceOnly'] ?? false));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function symbol(array $row): string
    {
        $coin = strtoupper($this->firstNonEmpty($row['coin'] ?? null, $row['symbol'] ?? null));
        if ($coin === '') {
            return '';
        }

        return str_ends_with($coin, 'USDT') || str_ends_with($coin, 'USDC') ? $coin : $coin . 'USDT';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isSupportedLifecycleRow(array $row): bool
    {
        return $this->hasValue($row['oid'] ?? null)
            || $this->hasValue($row['orderId'] ?? null)
            || $this->hasValue($row['cloid'] ?? null)
            || $this->hasValue($row['hash'] ?? null)
            || $this->hasValue($row['status'] ?? null)
            || $this->isFillRow($row);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isFillRow(array $row): bool
    {
        return $this->hasValue($row['hash'] ?? null)
            || $this->hasValue($row['tid'] ?? null)
            || $this->hasValue($row['fillId'] ?? null)
            || ($this->hasValue($row['px'] ?? null) && $this->hasValue($row['fee'] ?? null));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeLifecycleRow(array $row): array
    {
        if (!\is_array($row['order'] ?? null)) {
            return $row;
        }

        /** @var array<string,mixed> $wrapper */
        $wrapper = $row['order'];
        $details = \is_array($wrapper['order'] ?? null) ? $wrapper['order'] : $wrapper;
        /** @var array<string,mixed> $details */
        $normalized = array_merge($details, [
            'status' => $this->firstNonEmpty($wrapper['status'] ?? null, $details['status'] ?? null, $row['status'] ?? null),
            'uTime' => $this->firstNonEmpty(
                $wrapper['statusTimestamp'] ?? null,
                $row['statusTimestamp'] ?? null,
                $wrapper['uTime'] ?? null,
                $details['uTime'] ?? null,
                $row['uTime'] ?? null,
            ),
        ]);

        if ($normalized['status'] === 'order') {
            unset($normalized['status']);
        }
        if ($normalized['uTime'] === '') {
            unset($normalized['uTime']);
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $position
     */
    private function leverage(array $position): ?float
    {
        if (\is_array($position['leverage'] ?? null)) {
            /** @var array<string,mixed> $leverage */
            $leverage = $position['leverage'];

            return $this->floatOrNull($leverage['value'] ?? null);
        }

        return $this->floatOrNull($position['leverage'] ?? null);
    }

    /**
     * @param list<HyperliquidNormalizedFillDto> $fills
     */
    private function sumFillQuantity(array $fills): float
    {
        $quantity = 0.0;
        foreach ($fills as $fill) {
            $quantity += $fill->quantity;
        }

        return $quantity;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function rowTimeMillis(array $row): int
    {
        foreach ([$row['uTime'] ?? null, $row['updatedAt'] ?? null, $row['time'] ?? null, $row['timestamp'] ?? null] as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }

        return 0;
    }

    private function time(mixed $value): \DateTimeImmutable
    {
        return $this->timeOrNull($value) ?? new \DateTimeImmutable('@0');
    }

    private function timeOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!is_numeric($value)) {
            return null;
        }

        $millis = (int) $value;
        if ($millis > 9_999_999_999) {
            return new \DateTimeImmutable('@' . intdiv($millis, 1000));
        }

        return new \DateTimeImmutable('@' . $millis);
    }

    private function float(mixed $value): float
    {
        return $this->floatOrNull($value) ?? 0.0;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        return null;
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return \in_array(strtolower((string) $value), ['1', 'true', 'yes'], true);
    }

    private function firstNonEmpty(mixed ...$values): string
    {
        foreach ($values as $value) {
            if ($this->hasValue($value)) {
                return $this->string($value);
            }
        }

        return '';
    }

    private function string(mixed $value): string
    {
        if ($value instanceof \Stringable) {
            return trim((string) $value);
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function stringOrNull(mixed $value): ?string
    {
        $string = $this->string($value);

        return $string === '' ? null : $string;
    }

    private function hasValue(mixed $value): bool
    {
        return $this->string($value) !== '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function errorDataRow(array $payload): array
    {
        $response = \is_array($payload['response'] ?? null) ? $payload['response'] : $payload;
        /** @var array<string,mixed> $response */
        $data = \is_array($response['data'] ?? null) ? $response['data'] : [];
        /** @var array<string,mixed> $data */
        $statuses = \is_array($data['statuses'] ?? null) ? $data['statuses'] : [];
        foreach ($statuses as $status) {
            if (\is_array($status) && $this->hasValue($status['error'] ?? null)) {
                /** @var array<string,mixed> $status */
                return $status;
            }
        }

        return $response;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function redacted(array $payload): array
    {
        $redacted = [];
        foreach ($payload as $key => $value) {
            $keyString = (string) $key;
            if (preg_match(self::SENSITIVE_KEY_PATTERN, $keyString) === 1) {
                $redacted[$keyString] = '[redacted]';
                continue;
            }
            if (\is_array($value)) {
                /** @var array<string,mixed> $value */
                $redacted[$keyString] = $this->redacted($value);
                continue;
            }

            $redacted[$keyString] = $value;
        }

        return $redacted;
    }
}
