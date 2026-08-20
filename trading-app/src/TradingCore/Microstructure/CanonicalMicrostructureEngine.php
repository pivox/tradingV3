<?php

declare(strict_types=1);

namespace App\TradingCore\Microstructure;

use App\Trading\Paper\Backtesting\NormalizedBacktestPublicBook;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicTrade;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class CanonicalMicrostructureEngine
{
    /**
     * @param list<NormalizedBacktestPublicBook>  $books
     * @param list<NormalizedBacktestPublicTrade> $trades
     */
    public function build(
        CanonicalMicrostructurePolicy $policy,
        \DateTimeImmutable $evaluatedAt,
        array $books,
        array $trades,
    ): CanonicalMicrostructureSnapshot {
        return CanonicalMicrostructureSnapshot::fromRecords($policy, $evaluatedAt, $books, $trades);
    }

    /**
     * @param list<NormalizedBacktestPublicBook>  $books
     * @param list<NormalizedBacktestPublicTrade> $trades
     * @return array<string, mixed>
     */
    public function compute(
        CanonicalMicrostructurePolicy $policy,
        \DateTimeImmutable $evaluatedAt,
        array $books,
        array $trades,
    ): array {
        self::utc($evaluatedAt);
        $this->validateRecords($books, $trades);
        $availableBooks = array_values(array_filter($books, static fn (NormalizedBacktestPublicBook $book): bool => self::time($book->availableAt) <= $evaluatedAt));
        $book = $availableBooks[array_key_last($availableBooks)] ?? null;
        if ($book === null) {
            throw new CanonicalMicrostructureException('canonical_microstructure_book_unavailable');
        }
        if (self::ageSeconds(self::time($book->happenedAt), $evaluatedAt) > $policy->maximumBookAgeSeconds) {
            throw new CanonicalMicrostructureException('canonical_microstructure_book_stale');
        }

        $windowStart = $evaluatedAt->modify(sprintf('-%d seconds', $policy->windowSeconds));
        $windowTrades = array_values(array_filter(
            $trades,
            static function (NormalizedBacktestPublicTrade $trade) use ($windowStart, $evaluatedAt): bool {
                $happened = self::time($trade->happenedAt);

                return $happened >= $windowStart
                    && $happened <= $evaluatedAt
                    && self::time($trade->availableAt) <= $evaluatedAt;
            },
        ));
        if (count($windowTrades) < $policy->minimumTradeCount) {
            throw new CanonicalMicrostructureException('canonical_microstructure_trades_insufficient');
        }
        /** @var non-empty-list<NormalizedBacktestPublicTrade> $windowTrades */
        $first = $windowTrades[0];
        $last = $windowTrades[array_key_last($windowTrades)];
        if (self::ageSeconds(self::time($last->happenedAt), $evaluatedAt) > $policy->maximumTradeAgeSeconds) {
            throw new CanonicalMicrostructureException('canonical_microstructure_trades_stale');
        }
        $previous = $windowStart;
        foreach ($windowTrades as $trade) {
            $current = self::time($trade->happenedAt);
            if (self::ageSeconds($previous, $current) > $policy->maximumTradeGapSeconds) {
                throw new CanonicalMicrostructureException('canonical_microstructure_trade_gap');
            }
            $previous = $current;
        }
        if (self::ageSeconds($previous, $evaluatedAt) > $policy->maximumTradeGapSeconds) {
            throw new CanonicalMicrostructureException('canonical_microstructure_trade_gap');
        }

        $buy = BigDecimal::zero();
        $sell = BigDecimal::zero();
        foreach ($windowTrades as $trade) {
            $quantity = BigDecimal::of($trade->quantity);
            if ($trade->aggressorSide === 'buy') {
                $buy = $buy->plus($quantity);
            } else {
                $sell = $sell->plus($quantity);
            }
        }
        $total = $buy->plus($sell);
        if (!$total->isPositive()) {
            throw new CanonicalMicrostructureException('canonical_microstructure_arithmetic_invalid');
        }
        $imbalance = $buy->minus($sell)->dividedBy($total, 12, RoundingMode::HALF_EVEN);
        $bid = BigDecimal::of($book->bidPrice);
        $ask = BigDecimal::of($book->askPrice);
        $mid = $ask->plus($bid)->dividedBy(2, 24, RoundingMode::HALF_EVEN);
        $spread = $ask->minus($bid)->multipliedBy(10_000)->dividedBy($mid, 12, RoundingMode::HALF_EVEN);

        $arguments = [
            'sourceNetwork' => $book->sourceNetwork,
            'marketDataVenue' => $book->marketDataVenue,
            'marketType' => 'perpetual',
            'symbol' => $book->symbol,
            'sourceChecksum' => $book->sourceChecksum,
            'evaluatedAt' => self::format($evaluatedAt),
            'windowStart' => self::format($windowStart),
            'policy' => $policy->toArray(),
            'bookSourceRecordId' => $book->sourceRecordId,
            'bookHappenedAt' => $book->happenedAt,
            'bookAvailableAt' => $book->availableAt,
            'bestBid' => self::decimal($bid),
            'bestAsk' => self::decimal($ask),
            'spreadBps' => self::decimal($spread),
            'quantityUnit' => $first->quantityUnit,
            'tradeCount' => count($windowTrades),
            'buyQuantity' => self::decimal($buy),
            'sellQuantity' => self::decimal($sell),
            'totalQuantity' => self::decimal($total),
            'orderFlowImbalance' => self::decimal($imbalance),
            'firstTradeHappenedAt' => $first->happenedAt,
            'lastTradeHappenedAt' => $last->happenedAt,
            'lastTradeAvailableAt' => $last->availableAt,
            'tradeSourceRecordIds' => array_map(static fn (NormalizedBacktestPublicTrade $trade): string => $trade->sourceRecordId, $windowTrades),
        ];
        return $arguments;
    }

    /**
     * @param list<NormalizedBacktestPublicBook>  $books
     * @param list<NormalizedBacktestPublicTrade> $trades
     */
    private function validateRecords(array $books, array $trades): void
    {
        if (array_any($books, static fn (mixed $book): bool => !$book instanceof NormalizedBacktestPublicBook)
            || array_any($trades, static fn (mixed $trade): bool => !$trade instanceof NormalizedBacktestPublicTrade)
        ) {
            throw new CanonicalMicrostructureException('canonical_microstructure_input_invalid');
        }
        $records = [...$books, ...$trades];
        if ($records === []) {
            return;
        }
        $first = $records[0];
        foreach ($records as $record) {
            if ($record->sourceNetwork !== $first->sourceNetwork
                || $record->marketDataVenue !== $first->marketDataVenue
                || $record->symbol !== $first->symbol
                || $record->sourceChecksum !== $first->sourceChecksum
                || $record->quantityUnit !== $first->quantityUnit
            ) {
                throw new CanonicalMicrostructureException('canonical_microstructure_identity_mismatch');
            }
        }
        $this->canonicalOrder($books);
        $this->canonicalOrder($trades);
    }

    /** @param list<NormalizedBacktestPublicBook>|list<NormalizedBacktestPublicTrade> $records */
    private function canonicalOrder(array $records): void
    {
        $ids = [];
        $previous = null;
        foreach ($records as $record) {
            $key = [$record->availableAt, $record->happenedAt, $record->sourceRecordId];
            if (isset($ids[$record->sourceRecordId]) || ($previous !== null && $key < $previous)) {
                throw new CanonicalMicrostructureException('canonical_microstructure_chronology_invalid');
            }
            $ids[$record->sourceRecordId] = true;
            $previous = $key;
        }
    }

    private static function decimal(BigDecimal $value): string
    {
        return (string) $value->stripTrailingZeros();
    }

    private static function time(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private static function utc(\DateTimeImmutable $value): void
    {
        if ($value->getOffset() !== 0) {
            throw new CanonicalMicrostructureException('canonical_microstructure_time_invalid');
        }
    }

    private static function format(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function ageSeconds(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $seconds = (float) $to->format('U.u') - (float) $from->format('U.u');
        if (!\is_finite($seconds) || $seconds < 0.0) {
            throw new CanonicalMicrostructureException('canonical_microstructure_time_invalid');
        }

        return $seconds;
    }
}
