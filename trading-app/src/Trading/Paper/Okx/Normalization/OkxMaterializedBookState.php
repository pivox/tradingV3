<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Normalization;

use Brick\Math\BigDecimal;

/**
 * Immutable proof that both sides of an OKX order book have been completely materialized.
 *
 * Task 4 may call fromAppliedDelta() only after it has applied a raw update to its full book.
 */
final readonly class OkxMaterializedBookState
{
    /**
     * @param non-empty-list<array{price: string, size: string, order_count: string}> $bids
     * @param non-empty-list<array{price: string, size: string, order_count: string}> $asks
     */
    private function __construct(
        private array $bids,
        private array $asks,
        public \DateTimeImmutable $exchangeTimestamp,
        public string $sourceSequence,
        public ?string $sourcePreviousSequence,
    ) {
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
        return self::bestLevel($this->bids, highest: true);
    }

    /** @return array{price: string, size: string, order_count: string} */
    public function bestAsk(): array
    {
        return self::bestLevel($this->asks, highest: false);
    }

    /** @param array<array-key, mixed> $state */
    private static function fromCompleteState(#[\SensitiveParameter] array $state): self
    {
        try {
            $bids = self::levels($state['bids'] ?? null);
            $asks = self::levels($state['asks'] ?? null);
            if (!BigDecimal::of(self::bestLevel($bids, highest: true)['price'])
                ->isLessThan(BigDecimal::of(self::bestLevel($asks, highest: false)['price']))
            ) {
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
                $exchangeTimestamp,
                $sourceSequence,
                $sourcePreviousSequence,
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
            if (!BigDecimal::of($price)->isGreaterThan(BigDecimal::zero())
                || !BigDecimal::of($size)->isGreaterThan(BigDecimal::zero())
                || !BigDecimal::of($orderCount)->isGreaterThan(BigDecimal::zero())
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
            $comparison = BigDecimal::of($level['price'])->compareTo(BigDecimal::of($best['price']));
            if (($highest && $comparison > 0) || (!$highest && $comparison < 0)) {
                $best = $level;
            }
        }

        return $best;
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
