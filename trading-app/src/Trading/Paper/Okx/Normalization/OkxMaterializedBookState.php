<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Normalization;

/**
 * Immutable proof that both sides of an OKX order book have been completely materialized.
 *
 * Task 4 may call fromAppliedDelta() only after it has applied a raw update to its full book.
 * The live materializer builds it incrementally instead (fromIncrementalDelta()): the complete
 * sides are then sorted only when asked for (bids(), asks()).
 */
final readonly class OkxMaterializedBookState
{
    /**
     * @param non-empty-array<array-key, array{price: string, size: string, order_count: string, raw_field_3?: string}> $bidLevels
     *        best first when $sorted, else validated levels by price in book insertion order
     * @param non-empty-array<array-key, array{price: string, size: string, order_count: string, raw_field_3?: string}> $askLevels
     * @param array{price: string, size: string, order_count: string} $bestBidLevel
     * @param array{price: string, size: string, order_count: string} $bestAskLevel
     */
    private function __construct(
        private array $bidLevels,
        private array $askLevels,
        private bool $sorted,
        public \DateTimeImmutable $exchangeTimestamp,
        public string $sourceSequence,
        public ?string $sourcePreviousSequence,
        private array $bestBidLevel,
        private array $bestAskLevel,
    ) {
    }

    /**
     * After an incremental websocket books delta: the materializer validated every level
     * that entered the book with the rules of levels() (acceptsLevel()) and kept its best
     * levels current, so a delta no longer re-sorts nor re-validates the whole book (800
     * levels at OKX depth, most of the capture's time under a burst).
     *
     * @param array<string, array{price: string, size: string, raw_field_3: string, order_count: string}> $bids
     * @param array<string, array{price: string, size: string, raw_field_3: string, order_count: string}> $asks
     * @param array{price: string, size: string, raw_field_3: string, order_count: string} $bestBid
     * @param array{price: string, size: string, raw_field_3: string, order_count: string} $bestAsk
     */
    public static function fromIncrementalDelta(
        #[\SensitiveParameter] array $bids,
        #[\SensitiveParameter] array $asks,
        #[\SensitiveParameter] array $bestBid,
        #[\SensitiveParameter] array $bestAsk,
        #[\SensitiveParameter] mixed $timestamp,
        #[\SensitiveParameter] mixed $sequence,
        #[\SensitiveParameter] mixed $previousSequence,
    ): self {
        try {
            if ($bids === []
                || $asks === []
                || self::comparePrices($bestBid['price'], $bestAsk['price']) >= 0
            ) {
                throw new \InvalidArgumentException();
            }

            return new self(
                $bids,
                $asks,
                false,
                self::timestamp($timestamp),
                self::sourceSequence($sequence),
                $previousSequence === null ? null : self::sourceSequence($previousSequence),
                self::publicLevel($bestBid),
                self::publicLevel($bestAsk),
            );
        } catch (\Throwable) {
            throw new \InvalidArgumentException('okx_paper_materialized_order_book_invalid');
        }
    }

    /**
     * A level acceptable in a complete book (levels()), for a level whose price, size and
     * third field and order count are already canonical: positive price, size and order count.
     *
     * @param array{price: string, size: string, order_count: string} $level
     */
    public static function acceptsLevel(#[\SensitiveParameter] array $level): bool
    {
        return self::isPositive($level['price'])
            && self::isPositive($level['size'])
            && self::isPositive($level['order_count']);
    }

    /** @return non-empty-list<array{price: string, size: string, order_count: string}> best first */
    public function bids(): array
    {
        return $this->sorted ? array_values($this->bidLevels) : self::sortedLevels($this->bidLevels, true);
    }

    /** @return non-empty-list<array{price: string, size: string, order_count: string}> best first */
    public function asks(): array
    {
        return $this->sorted ? array_values($this->askLevels) : self::sortedLevels($this->askLevels, false);
    }

    /**
     * Construct from a complete REST order-book response row or WS books snapshot row.
     *
     * @param array<array-key, mixed> $snapshot
     */
    public static function fromSnapshot(#[\SensitiveParameter] array $snapshot): self
    {
        return self::fromCompleteState($snapshot);
    }

    /**
     * Construct from the complete state produced after Task 4 has applied a WS books delta.
     *
     * @param array<array-key, mixed> $completeState
     */
    public static function fromAppliedDelta(#[\SensitiveParameter] array $completeState): self
    {
        return self::fromCompleteState($completeState);
    }

    /** @return array{price: string, size: string, order_count: string} */
    public function bestBid(): array
    {
        return $this->bestBidLevel;
    }

    /** @return array{price: string, size: string, order_count: string} */
    public function bestAsk(): array
    {
        return $this->bestAskLevel;
    }

    /** @param array<array-key, mixed> $state */
    private static function fromCompleteState(#[\SensitiveParameter] array $state): self
    {
        try {
            $bids = self::levels($state['bids'] ?? null);
            $asks = self::levels($state['asks'] ?? null);
            $bestBid = self::bestLevel($bids, highest: true);
            $bestAsk = self::bestLevel($asks, highest: false);
            if (self::comparePrices($bestBid['price'], $bestAsk['price']) >= 0) {
                throw new \InvalidArgumentException();
            }

            $sourceSequence = self::sourceSequence($state['seqId'] ?? null);
            $sourcePreviousSequence = array_key_exists('prevSeqId', $state)
                ? self::sourceSequence($state['prevSeqId'])
                : null;
            $exchangeTimestamp = self::timestamp($state['ts'] ?? null);

            return new self(
                $bids,
                $asks,
                true,
                $exchangeTimestamp,
                $sourceSequence,
                $sourcePreviousSequence,
                $bestBid,
                $bestAsk,
            );
        } catch (\Throwable) {
            throw new \InvalidArgumentException('okx_paper_materialized_order_book_invalid');
        }
    }

    /**
     * @return non-empty-list<array{price: string, size: string, order_count: string}>
     */
    private static function levels(#[\SensitiveParameter] mixed $rawLevels): array
    {
        if (!\is_array($rawLevels) || !array_is_list($rawLevels) || $rawLevels === []) {
            throw new \InvalidArgumentException();
        }

        $levels = [];
        foreach ($rawLevels as $rawLevel) {
            if (!\is_array($rawLevel) || !array_is_list($rawLevel) || \count($rawLevel) !== 4) {
                throw new \InvalidArgumentException();
            }
            $price = self::decimal($rawLevel[0] ?? null);
            $size = self::decimal($rawLevel[1] ?? null);
            self::unsignedIntegerString($rawLevel[2] ?? null);
            $orderCount = self::unsignedIntegerString($rawLevel[3] ?? null);
            if (!self::isPositive($price)
                || !self::isPositive($size)
                || !self::isPositive($orderCount)
            ) {
                throw new \InvalidArgumentException();
            }

            $levels[] = [
                'price' => $price,
                'size' => $size,
                'order_count' => $orderCount,
            ];
        }

        return $levels;
    }

    /**
     * @param non-empty-list<array{price: string, size: string, order_count: string}> $levels
     * @return array{price: string, size: string, order_count: string}
     */
    private static function bestLevel(array $levels, bool $highest): array
    {
        $best = $levels[0];
        foreach ($levels as $level) {
            $comparison = self::comparePrices($level['price'], $best['price']);
            if (($highest && $comparison > 0) || (!$highest && $comparison < 0)) {
                $best = $level;
            }
        }

        return $best;
    }

    /**
     * @param array<array-key, array{price: string, size: string, order_count: string, raw_field_3?: string}> $levels
     *
     * @return non-empty-list<array{price: string, size: string, order_count: string}>
     */
    private static function sortedLevels(array $levels, bool $descending): array
    {
        // The order of OkxPaperOrderBookMaterializer::sortedRows(): canonical prices padded
        // to common widths sort bytewise in numeric order, and the stable sort keeps the
        // book insertion order between numerically equal prices of different scales.
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
        $sorted = [];
        foreach (array_keys($sortKeys) as $key) {
            $sorted[] = self::publicLevel($levels[$key]);
        }
        if ($sorted === []) {
            throw new \InvalidArgumentException('okx_paper_materialized_order_book_invalid');
        }

        return $sorted;
    }

    /**
     * @param array{price: string, size: string, order_count: string, raw_field_3?: string} $level
     *
     * @return array{price: string, size: string, order_count: string}
     */
    private static function publicLevel(array $level): array
    {
        return [
            'price' => $level['price'],
            'size' => $level['size'],
            'order_count' => $level['order_count'],
        ];
    }

    /**
     * Exact numeric order of two canonical unsigned decimals (validated by decimal():
     * no sign, exponent or leading zeros), without BigDecimal parsing.
     */
    public static function comparePrices(string $left, string $right): int
    {
        $leftDot = strpos($left, '.');
        $rightDot = strpos($right, '.');
        $leftInteger = $leftDot === false ? $left : substr($left, 0, $leftDot);
        $rightInteger = $rightDot === false ? $right : substr($right, 0, $rightDot);
        $order = \strlen($leftInteger) <=> \strlen($rightInteger);
        if ($order === 0) {
            $order = strcmp($leftInteger, $rightInteger) <=> 0;
        }
        if ($order !== 0) {
            return $order;
        }
        $leftFraction = $leftDot === false ? '' : substr($left, $leftDot + 1);
        $rightFraction = $rightDot === false ? '' : substr($right, $rightDot + 1);
        $width = max(\strlen($leftFraction), \strlen($rightFraction));

        return strcmp(
            str_pad($leftFraction, $width, '0', \STR_PAD_RIGHT),
            str_pad($rightFraction, $width, '0', \STR_PAD_RIGHT),
        ) <=> 0;
    }

    /** A canonical unsigned decimal or integer is positive iff it has a non-zero digit. */
    private static function isPositive(string $value): bool
    {
        return strspn($value, '0.') !== \strlen($value);
    }

    private static function decimal(#[\SensitiveParameter] mixed $value): string
    {
        if (!\is_string($value)
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException();
        }

        return $value;
    }

    private static function unsignedIntegerString(#[\SensitiveParameter] mixed $value): string
    {
        if (!\is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new \InvalidArgumentException();
        }

        return $value;
    }

    private static function sourceSequence(#[\SensitiveParameter] mixed $value): string
    {
        if (\is_int($value)) {
            return (string) $value;
        }
        if (!\is_string($value) || preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new \InvalidArgumentException();
        }

        return $value;
    }

    private static function timestamp(#[\SensitiveParameter] mixed $value): \DateTimeImmutable
    {
        $milliseconds = self::unsignedIntegerString($value);
        if (\strlen($milliseconds) !== 13) {
            throw new \InvalidArgumentException();
        }

        $timestamp = \DateTimeImmutable::createFromFormat(
            '!U.u',
            substr($milliseconds, 0, 10) . '.' . substr($milliseconds, 10) . '000',
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
        ) {
            throw new \InvalidArgumentException();
        }

        return $timestamp->setTimezone(new \DateTimeZone('UTC'));
    }
}
