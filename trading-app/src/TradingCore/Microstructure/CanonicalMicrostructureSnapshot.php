<?php

declare(strict_types=1);

namespace App\TradingCore\Microstructure;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use App\TradingCore\Rules\Evaluation\RuleInputProof;

final readonly class CanonicalMicrostructureSnapshot implements RuleInputProof
{
    public const SCHEMA_VERSION = 'canonical-microstructure-snapshot.v1';
    public const OFI_DEFINITION = 'aggressor_volume_ratio.v1';

    public string $schemaVersion;
    public string $orderFlowImbalanceDefinition;
    public string $inputHash;

    /**
     * @param array<string, int|string> $policy
     * @param non-empty-list<string>    $tradeSourceRecordIds
     */
    public function __construct(
        public string $sourceNetwork,
        public string $marketDataVenue,
        public string $marketType,
        public string $symbol,
        public string $sourceChecksum,
        public string $evaluatedAt,
        public string $windowStart,
        public array $policy,
        public string $bookSourceRecordId,
        public string $bookHappenedAt,
        public string $bookAvailableAt,
        public string $bestBid,
        public string $bestAsk,
        public string $spreadBps,
        public string $quantityUnit,
        public int $tradeCount,
        public string $buyQuantity,
        public string $sellQuantity,
        public string $totalQuantity,
        public string $orderFlowImbalance,
        public string $firstTradeHappenedAt,
        public string $lastTradeHappenedAt,
        public string $lastTradeAvailableAt,
        public array $tradeSourceRecordIds,
        ?string $inputHash = null,
    ) {
        $this->schemaVersion = self::SCHEMA_VERSION;
        $this->orderFlowImbalanceDefinition = self::OFI_DEFINITION;
        $this->inputHash = $inputHash ?? self::hashPayload($this->payload());
        if (!\in_array($sourceNetwork, ['mainnet', 'testnet'], true)
            || !\in_array($marketDataVenue, ['okx', 'hyperliquid'], true)
            || $marketType !== 'perpetual'
            || preg_match('/\A[A-Z0-9][A-Z0-9_.-]*\z/D', $symbol) !== 1
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $sourceChecksum) !== 1
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $this->inputHash) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $bookSourceRecordId) !== 1
            || $tradeCount !== count($tradeSourceRecordIds)
            || $tradeCount < 1
            || count(array_unique($tradeSourceRecordIds)) !== $tradeCount
            || array_any($tradeSourceRecordIds, static fn (string $id): bool => preg_match('/\A[0-9a-f]{64}\z/D', $id) !== 1)
            || !$this->semanticsAreValid()
            || !hash_equals(self::hashPayload($this->payload()), $this->inputHash)
        ) {
            throw new CanonicalMicrostructureException('canonical_microstructure_snapshot_invalid');
        }
    }

    public function verify(): self
    {
        if (!hash_equals(self::hashPayload($this->payload()), $this->inputHash)) {
            throw new CanonicalMicrostructureException('canonical_microstructure_snapshot_hash_mismatch');
        }

        return $this;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new CanonicalMicrostructureException('canonical_microstructure_snapshot_hydration_forbidden');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new CanonicalMicrostructureException('canonical_microstructure_snapshot_hydration_forbidden');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload() + ['input_hash' => $this->inputHash];
    }

    /** @param array<string, mixed> $payload */
    public static function hashPayload(array $payload): string
    {
        $canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
            if (!\is_array($value)) {
                return $value;
            }
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $canonicalize($item);
            }
            if (!array_is_list($result)) {
                ksort($result, SORT_STRING);
            }

            return $result;
        };

        try {
            $encoded = json_encode(
                $canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
            );
        } catch (\JsonException $exception) {
            throw new CanonicalMicrostructureException('canonical_microstructure_snapshot_invalid', [], $exception);
        }

        return 'sha256:' . hash('sha256', $encoded);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'source_network' => $this->sourceNetwork,
            'market_data_venue' => $this->marketDataVenue,
            'market_type' => $this->marketType,
            'symbol' => $this->symbol,
            'source_checksum' => $this->sourceChecksum,
            'evaluated_at' => $this->evaluatedAt,
            'window_start' => $this->windowStart,
            'policy' => $this->policy,
            'book' => [
                'source_record_id' => $this->bookSourceRecordId,
                'happened_at' => $this->bookHappenedAt,
                'available_at' => $this->bookAvailableAt,
                'best_bid' => $this->bestBid,
                'best_ask' => $this->bestAsk,
                'spread_bps' => $this->spreadBps,
            ],
            'flow' => [
                'definition' => self::OFI_DEFINITION,
                'quantity_unit' => $this->quantityUnit,
                'trade_count' => $this->tradeCount,
                'buy_quantity' => $this->buyQuantity,
                'sell_quantity' => $this->sellQuantity,
                'total_quantity' => $this->totalQuantity,
                'order_flow_imbalance' => $this->orderFlowImbalance,
                'first_trade_happened_at' => $this->firstTradeHappenedAt,
                'last_trade_happened_at' => $this->lastTradeHappenedAt,
                'last_trade_available_at' => $this->lastTradeAvailableAt,
                'source_record_ids' => $this->tradeSourceRecordIds,
            ],
        ];
    }

    private function semanticsAreValid(): bool
    {
        if (array_keys($this->policy) !== [
            'schema_version',
            'window_seconds',
            'maximum_book_age_seconds',
            'maximum_trade_age_seconds',
            'maximum_trade_gap_seconds',
            'minimum_trade_count',
        ]) {
            return false;
        }
        try {
            $policy = new CanonicalMicrostructurePolicy(
                self::integer($this->policy['window_seconds']),
                self::integer($this->policy['maximum_book_age_seconds']),
                self::integer($this->policy['maximum_trade_age_seconds']),
                self::integer($this->policy['maximum_trade_gap_seconds']),
                self::integer($this->policy['minimum_trade_count']),
            );
            if ($policy->toArray() !== $this->policy) {
                return false;
            }
            $bid = self::positiveDecimal($this->bestBid);
            $ask = self::positiveDecimal($this->bestAsk);
            $spread = self::positiveDecimal($this->spreadBps);
            $buy = self::nonNegativeDecimal($this->buyQuantity);
            $sell = self::nonNegativeDecimal($this->sellQuantity);
            $total = self::positiveDecimal($this->totalQuantity);
            $imbalance = self::signedDecimal($this->orderFlowImbalance);
            if ($bid->isGreaterThanOrEqualTo($ask)
                || !$buy->plus($sell)->isEqualTo($total)
                || $imbalance->isLessThan(BigDecimal::of('-1'))
                || $imbalance->isGreaterThan(BigDecimal::one())
                || !$buy->minus($sell)->dividedBy($total, 12, RoundingMode::HALF_EVEN)->isEqualTo($imbalance)
            ) {
                return false;
            }
            $expectedSpread = $ask->minus($bid)->multipliedBy(10_000)->dividedBy(
                $ask->plus($bid)->dividedBy(2, 24, RoundingMode::HALF_EVEN),
                12,
                RoundingMode::HALF_EVEN,
            );
            if (!$expectedSpread->isEqualTo($spread)) {
                return false;
            }
            $evaluated = self::timestamp($this->evaluatedAt);
            $window = self::timestamp($this->windowStart);
            $bookHappened = self::timestamp($this->bookHappenedAt);
            $bookAvailable = self::timestamp($this->bookAvailableAt);
            $first = self::timestamp($this->firstTradeHappenedAt);
            $last = self::timestamp($this->lastTradeHappenedAt);
            $lastAvailable = self::timestamp($this->lastTradeAvailableAt);

            return $window < $evaluated
                && $bookHappened <= $bookAvailable
                && $bookAvailable <= $evaluated
                && $window <= $first
                && $first <= $last
                && $last <= $lastAvailable
                && $lastAvailable <= $evaluated;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function integer(mixed $value): int
    {
        if (!\is_int($value)) {
            throw new \InvalidArgumentException();
        }

        return $value;
    }

    private static function positiveDecimal(string $value): BigDecimal
    {
        $decimal = self::decimal($value, false);
        if (!$decimal->isPositive()) {
            throw new \InvalidArgumentException();
        }

        return $decimal;
    }

    private static function nonNegativeDecimal(string $value): BigDecimal
    {
        $decimal = self::decimal($value, false);
        if ($decimal->isNegative()) {
            throw new \InvalidArgumentException();
        }

        return $decimal;
    }

    private static function signedDecimal(string $value): BigDecimal
    {
        return self::decimal($value, true);
    }

    private static function decimal(string $value, bool $signed): BigDecimal
    {
        $pattern = $signed
            ? '/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?\z/D'
            : '/\A(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?\z/D';
        if (\strlen($value) > 256 || preg_match($pattern, $value) !== 1 || $value === '-0') {
            throw new \InvalidArgumentException();
        }

        return BigDecimal::of($value);
    }

    private static function timestamp(string $value): \DateTimeImmutable
    {
        $timestamp = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $value,
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $timestamp->format('Y-m-d\TH:i:s.u\Z') !== $value
        ) {
            throw new \InvalidArgumentException();
        }

        return $timestamp;
    }
}
