<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\Okx\Normalization\OkxMaterializedBookState;
use Brick\Math\BigInteger;

final class OkxPaperOrderBookMaterializer
{
    /** @var array<string, array{price: string, size: string, raw_field_3: string, order_count: string}> */
    private array $bids = [];

    /** @var array<string, array{price: string, size: string, raw_field_3: string, order_count: string}> */
    private array $asks = [];

    /** Keys of the best bid and ask in $bids and $asks, kept current across deltas. */
    private ?string $bestBidPrice = null;
    private ?string $bestAskPrice = null;

    private ?string $currentSequence = null;
    private ?string $lastDeltaPreviousSequence = null;
    private ?string $lastDeltaSequence = null;
    /**
     * The last applied delta without its checksum: its canonical hash is computed
     * only when OKX sends the same sequence again (see identityHash()).
     *
     * @var array<array-key, mixed>|null
     */
    private ?array $lastDeltaIdentity = null;

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
        $this->bestBidPrice = self::scannedBestPrice($candidateBids, highest: true);
        $this->bestAskPrice = self::scannedBestPrice($candidateAsks, highest: false);
        $this->currentSequence = $sequence;
        $this->lastDeltaPreviousSequence = null;
        $this->lastDeltaSequence = null;
        $this->lastDeltaIdentity = null;

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
        $bidUpdates = self::deltaLevels($delta['bids'] ?? null);
        $askUpdates = self::deltaLevels($delta['asks'] ?? null);

        $deltaIdentity = $delta;
        unset($deltaIdentity['checksum']);
        if (!self::identityCannotFailToEncode($deltaIdentity, $bidUpdates, $askUpdates)) {
            // Rejected by CanonicalJson as before: only unusual rows are encoded here.
            self::identityHash($deltaIdentity);
        }
        if ($previousSequence === $this->lastDeltaPreviousSequence
            && $sequence === $this->lastDeltaSequence
        ) {
            if ($this->lastDeltaIdentity !== null
                && hash_equals(self::identityHash($this->lastDeltaIdentity), self::identityHash($deltaIdentity))
            ) {
                return OkxPaperBookDeltaResult::replayed();
            }

            throw new \RuntimeException('market_event_identity_conflict');
        }

        if ($previousSequence !== $this->currentSequence) {
            throw new \RuntimeException('okx_paper_book_sequence_gap');
        }
        if ($sequence === $previousSequence) {
            if ($bidUpdates === [] && $askUpdates === []) {
                return OkxPaperBookDeltaResult::replayed();
            }

            throw new \InvalidArgumentException('okx_paper_book_sequence_invalid');
        }
        $candidateBids = self::applyLevelUpdates($this->bids, $bidUpdates);
        $candidateAsks = self::applyLevelUpdates($this->asks, $askUpdates);
        // The complete book is neither re-sorted nor re-validated: every level entering it
        // (the final value of each updated price) passes the complete-book rules here, and
        // the best levels follow the updates.
        foreach ([[$candidateBids, $bidUpdates], [$candidateAsks, $askUpdates]] as [$candidate, $updates]) {
            foreach ($updates as $level) {
                $entered = $candidate[$level['price']] ?? null;
                if ($entered !== null && !OkxMaterializedBookState::acceptsLevel($entered)) {
                    throw self::invalidBook();
                }
            }
        }
        $bestBidPrice = self::bestPriceAfter($this->bestBidPrice, $candidateBids, $bidUpdates, highest: true);
        $bestAskPrice = self::bestPriceAfter($this->bestAskPrice, $candidateAsks, $askUpdates, highest: false);
        if ($bestBidPrice === null || $bestAskPrice === null) {
            throw self::invalidBook();
        }
        $state = OkxMaterializedBookState::fromIncrementalDelta(
            $candidateBids,
            $candidateAsks,
            $candidateBids[$bestBidPrice],
            $candidateAsks[$bestAskPrice],
            $delta['ts'] ?? null,
            $sequence,
            $previousSequence,
        );

        $this->bids = $candidateBids;
        $this->asks = $candidateAsks;
        $this->bestBidPrice = $bestBidPrice;
        $this->bestAskPrice = $bestAskPrice;
        $this->currentSequence = $sequence;
        $this->lastDeltaPreviousSequence = $previousSequence;
        $this->lastDeltaSequence = $sequence;
        $this->lastDeltaIdentity = $deltaIdentity;

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
        // decimal() accepts canonical unsigned decimals only: never negative, and
        // positive iff a digit is not zero (no BigDecimal parse per level).
        if (!self::isPositive($price) || (!$allowZeroSize && !self::isPositive($size))) {
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
            if (!self::isPositive($level['size'])) {
                unset($candidate[$level['price']]);
            } else {
                $candidate[$level['price']] = $level;
            }
        }

        return $candidate;
    }

    /**
     * The best price of a side after a delta, as a full sort would find it: the numeric
     * best, and between numerically equal prices of different scales the earliest in book
     * insertion order. A deletion of the previous best (even re-inserted, which moves it to
     * the end of that order) rescans the side; otherwise only the updated levels can win.
     *
     * @param array<string, array{price: string, size: string, raw_field_3: string, order_count: string}> $candidate
     * @param list<array{price: string, size: string, raw_field_3: string, order_count: string}>          $updates
     */
    private static function bestPriceAfter(?string $previousBest, array $candidate, array $updates, bool $highest): ?string
    {
        if ($previousBest === null || !isset($candidate[$previousBest])) {
            return self::scannedBestPrice($candidate, $highest);
        }
        foreach ($updates as $level) {
            if ($level['price'] === $previousBest && !self::isPositive($level['size'])) {
                return self::scannedBestPrice($candidate, $highest);
            }
        }
        $best = $previousBest;
        foreach ($updates as $level) {
            if (!isset($candidate[$level['price']]) || $level['price'] === $best) {
                continue;
            }
            $comparison = OkxMaterializedBookState::comparePrices($level['price'], $best);
            if ($highest ? $comparison > 0 : $comparison < 0) {
                $best = $level['price'];
            }
        }

        return $best;
    }

    /** @param array<string, array{price: string, size: string, raw_field_3: string, order_count: string}> $levels */
    private static function scannedBestPrice(array $levels, bool $highest): ?string
    {
        $best = null;
        foreach ($levels as $price => $level) {
            $price = (string) $price;
            if ($best === null) {
                $best = $price;

                continue;
            }
            $comparison = OkxMaterializedBookState::comparePrices($price, $best);
            if ($highest ? $comparison > 0 : $comparison < 0) {
                $best = $price;
            }
        }

        return $best;
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
        // Prices were validated as canonical unsigned decimals by decimal(). Padding
        // integer parts left and fractions right to common widths yields keys whose
        // byte order is exactly the numeric order, so the native stable sort replaces
        // thousands of BigDecimal parses per full-book delta.
        $integerWidth = 0;
        $fractionWidth = 0;
        $parts = [];
        foreach ($levels as $key => $level) {
            $dot = strpos($level['price'], '.');
            $integer = $dot === false ? $level['price'] : substr($level['price'], 0, $dot);
            $fraction = $dot === false ? '' : substr($level['price'], $dot + 1);
            $parts[$key] = [$integer, $fraction];
            $integerWidth = max($integerWidth, \strlen($integer));
            $fractionWidth = max($fractionWidth, \strlen($fraction));
        }
        $sortKeys = [];
        foreach ($parts as $key => [$integer, $fraction]) {
            $sortKeys[$key] = str_pad($integer, $integerWidth, '0', \STR_PAD_LEFT)
                . str_pad($fraction, $fractionWidth, '0', \STR_PAD_RIGHT);
        }
        if ($descending) {
            arsort($sortKeys, \SORT_STRING);
        } else {
            asort($sortKeys, \SORT_STRING);
        }

        $rows = [];
        foreach ($sortKeys as $key => $_sortKey) {
            $level = $levels[$key];
            $rows[] = [
                $level['price'],
                $level['size'],
                $level['raw_field_3'],
                $level['order_count'],
            ];
        }

        return $rows;
    }

    /** A canonical unsigned decimal (decimal()) is positive iff it has a non-zero digit. */
    private static function isPositive(string $value): bool
    {
        return strspn($value, '0.') !== \strlen($value);
    }

    /** @param array<array-key, mixed> $identity */
    private static function identityHash(#[\SensitiveParameter] array $identity): string
    {
        return hash('sha256', CanonicalJson::encode($identity));
    }

    /**
     * Whether CanonicalJson::encode() of this delta identity cannot fail (so it may
     * be hashed only if ever needed): the books keys only, levels validated by
     * deltaLevels(), and nodes and bytes far below CanonicalJson's budgets.
     *
     * @param array<array-key, mixed>                                                   $identity
     * @param list<array{price: string, size: string, raw_field_3: string, order_count: string}> $bids
     * @param list<array{price: string, size: string, raw_field_3: string, order_count: string}> $asks
     */
    private static function identityCannotFailToEncode(
        #[\SensitiveParameter] array $identity,
        array $bids,
        array $asks,
    ): bool {
        if (\count($bids) + \count($asks) > 3_000
            || array_diff_key($identity, ['asks' => true, 'bids' => true, 'ts' => true, 'seqId' => true, 'prevSeqId' => true]) !== []
        ) {
            return false;
        }
        foreach (['ts', 'seqId', 'prevSeqId'] as $key) {
            $value = $identity[$key] ?? null;
            if ($value !== null && !\is_int($value) && !(\is_string($value) && \strlen($value) <= 64)) {
                return false;
            }
        }
        foreach ([...$bids, ...$asks] as $level) {
            if (\strlen($level['price']) + \strlen($level['size'])
                + \strlen($level['raw_field_3']) + \strlen($level['order_count']) > 160
            ) {
                return false;
            }
        }

        return true;
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
