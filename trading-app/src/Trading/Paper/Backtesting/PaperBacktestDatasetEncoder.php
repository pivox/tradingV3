<?php

declare(strict_types=1);

namespace App\Trading\Paper\Backtesting;

use App\Trading\Paper\MarketData\CanonicalJson;

final class PaperBacktestDatasetEncoder
{
    /** @var list<string> */
    private const FORBIDDEN_KEYS = ['mode', 'setup', 'profile', 'strategy'];

    public function sourceIdentity(PaperBacktestDataset $dataset): string
    {
        $this->assertNoStrategyIdentity($dataset->sourceIdentity);

        return CanonicalJson::encode($dataset->sourceIdentity) . "\n";
    }

    public function candles(PaperBacktestDataset $dataset): string
    {
        $encoded = '';
        foreach ($dataset->candles as $candle) {
            $value = $candle->toArray();
            $this->assertNoStrategyIdentity($value);
            $encoded .= CanonicalJson::encode($value) . "\n";
        }

        return $encoded;
    }

    public function publicTrades(PaperBacktestDataset $dataset): string
    {
        $encoded = '';
        foreach ($dataset->publicTrades as $trade) {
            $value = $trade->toArray();
            $this->assertNoStrategyIdentity($value);
            $encoded .= CanonicalJson::encode($value) . "\n";
        }
        return $encoded;
    }

    public function publicBooks(PaperBacktestDataset $dataset): string
    {
        $encoded = '';
        foreach ($dataset->publicBooks as $book) {
            $value = $book->toArray();
            $this->assertNoStrategyIdentity($value);
            $encoded .= CanonicalJson::encode($value) . "\n";
        }
        return $encoded;
    }

    private function assertNoStrategyIdentity(mixed $value): void
    {
        if (!\is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if (\is_string($key) && \in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                throw new \InvalidArgumentException('paper_backtest_strategy_identity_forbidden');
            }
            $this->assertNoStrategyIdentity($item);
        }
    }
}
