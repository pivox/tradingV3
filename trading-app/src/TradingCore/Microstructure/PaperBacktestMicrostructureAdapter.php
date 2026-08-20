<?php

declare(strict_types=1);

namespace App\TradingCore\Microstructure;

use App\Trading\Paper\Backtesting\PaperBacktestDataset;

final readonly class PaperBacktestMicrostructureAdapter
{
    public function adapt(
        PaperBacktestDataset $dataset,
        CanonicalMicrostructurePolicy $policy,
        \DateTimeImmutable $evaluatedAt,
        string $symbol,
    ): CanonicalMicrostructureSnapshot {
        $books = array_values(array_filter(
            $dataset->publicBooks,
            static fn ($book): bool => $book->symbol === $symbol,
        ));
        $trades = array_values(array_filter(
            $dataset->publicTrades,
            static fn ($trade): bool => $trade->symbol === $symbol,
        ));

        return (new CanonicalMicrostructureEngine())->build($policy, $evaluatedAt, $books, $trades);
    }
}
