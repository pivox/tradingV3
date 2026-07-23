<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\Okx\Normalization\OkxMaterializedBookState;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;

final class OkxPaperOrderBookMaterializer
{
    /** @var array<string, array{price: string, size: string, raw_field_3: string, order_count: string}> */
    private array $bids = [];

    /** @var array<string, array{price: string, size: string, raw_field_3: string, order_count: string}> */
    private array $asks = [];

    private ?string $currentSequence = null;
    private ?string $lastDeltaPreviousSequence = null;
    private ?string $lastDeltaSequence = null;
    private ?string $lastDeltaHash = null;

    /** @param array<array-key, mixed> $snapshot */
    public function replaceSnapshot(#[\SensitiveParameter] array $snapshot): OkxMaterializedBookState
    {
        $sequence = self::nonNegativeSequence($snapshot['seqId'] ?? null);
        $previousSequence = null;
        if (array_key_exists('prevSeqId', $snapshot)) {
            $previousSequence = self::snapshotPreviousSequence($snapshot['prevSeqId']);
        }

        $candidateBids = self::snapshotLevels($snapshot['bids'] ?? null);
        $candidateAsks = self::snapshotLevels($snapshot['asks'] ?? null);
        $completeState = self::completeState(
            $candidateBids,
            $candidateAsks,
            $snapshot['ts'] ?? null,
            $previousSequence,
            $sequence,
        );
        $state = OkxMaterializedBookState::fromSnapshot($completeState);

        $this->bids = $candidateBids;
        $this->asks = $candidateAsks;
        $this->currentSequence = $sequence;
        $this->lastDeltaPreviousSequence = null;
        $this->lastDeltaSequence = null;
        $this->lastDeltaHash = null;

        return $state;
    }

    /** @param array<array-key, mixed> $delta */
    public function applyDelta(#[\SensitiveParameter] array $delta): OkxPaperBookDeltaResult
    {
        if ($this->currentSequence === null) {
            throw new \InvalidArgumentException('okx_paper_book_snapshot_required');
        }

        $previousSequence = self::nonNegativeSequence($delta['prevSeqId'] ?? null);
        $sequence = self::nonNegativeSequence($delta['seqId'] ?? null);
        if (!BigInteger::of($sequence)->isGreaterThan(BigInteger::of($previousSequence))) {
            throw new \InvalidArgumentException('okx_paper_book_sequence_invalid');
        }

        $deltaHash = hash('sha256', CanonicalJson::encode($delta));
        if ($previousSequence === $this->lastDeltaPreviousSequence
            && $sequence === $this->lastDeltaSequence
        ) {
            if ($this->lastDeltaHash !== null && hash_equals($this->lastDeltaHash, $deltaHash)) {
                return OkxPaperBookDeltaResult::replayed();
            }

            throw new \RuntimeException('market_event_identity_conflict');
        }

        if ($previousSequence !== $this->currentSequence) {
            throw new \RuntimeException('okx_paper_book_sequence_gap');
        }

        $bidUpdates = self::deltaLevels($delta['bids'] ?? null);
        $askUpdates = self::deltaLevels($delta['asks'] ?? null);
        $candidateBids = self::applyLevelUpdates($this->bids, $bidUpdates);
        $candidateAsks = self::applyLevelUpdates($this->asks, $askUpdates);
        $completeState = self::completeState(
            $candidateBids,
            $candidateAsks,
            $delta['ts'] ?? null,
            $previousSequence,
            $sequence,
        );
        $state = OkxMaterializedBookState::fromAppliedDelta($completeState);

        $this->bids = $candidateBids;
        $this->asks = $candidateAsks;
        $this->currentSequence = $sequence;
        $this->lastDeltaPreviousSequence = $previousSequence;
        $this->lastDeltaSequence = $sequence;
        $this->lastDeltaHash = $deltaHash;

        return OkxPaperBookDeltaResult::applied($state);
    }

    public function sourceSequence(): ?string
    {
        return $this->currentSequence;
    }

    /**
     * @return array<string, array{price: string, size: string, raw_field_3: string, order_count: string}>
     */
    private static function snapshotLevels(#[\SensitiveParameter] mixed $rawLevels): array
    {
        if (!\is_array($rawLevels) || !array_is_list($rawLevels) || $rawLevels === []) {
            throw self::invalidBook();
        }

        $levels = [];
        foreach ($rawLevels as $rawLevel) {
            $level = self::level($rawLevel, allowZeroSize: false);
            if (array_key_exists($level['price'], $levels)) {
                throw self::invalidBook();
            }
            $levels[$level['price']] = $level;
        }

        return $levels;
    }

    /**
     * @return list<array{price: string, size: string, raw_field_3: string, order_count: string}>
     */
    private static function deltaLevels(#[\SensitiveParameter] mixed $rawLevels): array
    {
        if (!\is_array($rawLevels) || !array_is_list($rawLevels)) {
            throw self::invalidBook();
        }

        $levels = [];
        foreach ($rawLevels as $rawLevel) {
            $levels[] = self::level($rawLevel, allowZeroSize: true);
        }

        return $levels;
    }

    /**
     * @return array{price: string, size: string, raw_field_3: string, order_count: string}
     */
    private static function level(#[\SensitiveParameter] mixed $rawLevel, bool $allowZeroSize): array
    {
        if (!\is_array($rawLevel) || !array_is_list($rawLevel) || \count($rawLevel) !== 4) {
            throw self::invalidBook();
        }

        $price = self::decimal($rawLevel[0] ?? null);
        $size = self::decimal($rawLevel[1] ?? null);
        $rawField3 = self::unsignedInteger($rawLevel[2] ?? null);
        $orderCount = self::unsignedInteger($rawLevel[3] ?? null);
        if (!BigDecimal::of($price)->isGreaterThan(BigDecimal::zero())) {
            throw self::invalidBook();
        }

        $decimalSize = BigDecimal::of($size);
        if ($allowZeroSize) {
            if ($decimalSize->isLessThan(BigDecimal::zero())) {
                throw self::invalidBook();
            }
        } elseif (!$decimalSize->isGreaterThan(BigDecimal::zero())) {
            throw self::invalidBook();
        }

        return [
            'price' => $price,
            'size' => $size,
            'raw_field_3' => $rawField3,
            'order_count' => $orderCount,
        ];
    }

    /**
     * @param array<string, array{price: string, size: string, raw_field_3: string, order_count: string}> $current
     * @param list<array{price: string, size: string, raw_field_3: string, order_count: string}>          $updates
     *
     * @return array<string, array{price: string, size: string, raw_field_3: string, order_count: string}>
     */
    private static function applyLevelUpdates(array $current, array $updates): array
    {
        $candidate = $current;
        foreach ($updates as $level) {
            if (BigDecimal::of($level['size'])->isZero()) {
                unset($candidate[$level['price']]);
            } else {
                $candidate[$level['price']] = $level;
            }
        }

        return $candidate;
    }

    /**
     * @param array<string, array{price: string, size: string, raw_field_3: string, order_count: string}> $bids
     * @param array<string, array{price: string, size: string, raw_field_3: string, order_count: string}> $asks
     *
     * @return array{
     *     bids: list<list<string>>,
     *     asks: list<list<string>>,
     *     ts: mixed,
     *     seqId: string,
     *     prevSeqId?: string
     * }
     */
    private static function completeState(
        array $bids,
        array $asks,
        #[\SensitiveParameter] mixed $timestamp,
        ?string $previousSequence,
        string $sequence,
    ): array {
        $state = [
            'bids' => self::sortedRows($bids, descending: true),
            'asks' => self::sortedRows($asks, descending: false),
            'ts' => $timestamp,
            'seqId' => $sequence,
        ];
        if ($previousSequence !== null) {
            $state['prevSeqId'] = $previousSequence;
        }

        return $state;
    }

    /**
     * @param array<string, array{price: string, size: string, raw_field_3: string, order_count: string}> $levels
     *
     * @return list<list<string>>
     */
    private static function sortedRows(array $levels, bool $descending): array
    {
        uasort(
            $levels,
            static function (array $left, array $right) use ($descending): int {
                $comparison = BigDecimal::of($left['price'])->compareTo(BigDecimal::of($right['price']));

                return $descending ? -$comparison : $comparison;
            },
        );

        return array_values(array_map(
            static fn (array $level): array => [
                $level['price'],
                $level['size'],
                $level['raw_field_3'],
                $level['order_count'],
            ],
            $levels,
        ));
    }

    private static function decimal(#[\SensitiveParameter] mixed $value): string
    {
        if (!\is_string($value)
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) !== 1
        ) {
            throw self::invalidBook();
        }

        return $value;
    }

    private static function unsignedInteger(#[\SensitiveParameter] mixed $value): string
    {
        if (!\is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw self::invalidBook();
        }

        return $value;
    }

    private static function nonNegativeSequence(#[\SensitiveParameter] mixed $value): string
    {
        if (\is_int($value)) {
            $value = (string) $value;
        }
        if (!\is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('okx_paper_book_sequence_invalid');
        }

        return $value;
    }

    private static function snapshotPreviousSequence(#[\SensitiveParameter] mixed $value): string
    {
        if ($value === -1 || $value === '-1') {
            return '-1';
        }

        return self::nonNegativeSequence($value);
    }

    private static function invalidBook(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('okx_paper_materialized_order_book_invalid');
    }
}
