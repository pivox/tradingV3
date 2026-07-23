<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Http;

enum OkxPublicEndpoint: string
{
    case HistoryCandles = '/api/v5/market/history-candles';
    case CurrentCandles = '/api/v5/market/candles';
    case HistoryTrades = '/api/v5/market/history-trades';
    case RecentTrades = '/api/v5/market/trades';
    case OrderBook = '/api/v5/market/books';

    public function maximumLimit(): int
    {
        return match ($this) {
            self::HistoryCandles, self::CurrentCandles => 300,
            self::HistoryTrades => 100,
            self::RecentTrades => 500,
            self::OrderBook => 400,
        };
    }

    public function usesHistoryRateLimit(): bool
    {
        return $this === self::HistoryCandles || $this === self::HistoryTrades;
    }
}
