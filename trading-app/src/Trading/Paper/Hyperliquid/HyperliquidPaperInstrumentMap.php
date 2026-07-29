<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid;

final readonly class HyperliquidPaperInstrumentMap
{
    /** @var array<string, string> */
    private const NORMALIZED_BY_NATIVE_COIN = [
        'BTC' => 'BTCUSDT',
        'ETH' => 'ETHUSDT',
    ];

    /** @var array<string, int> */
    private const MILLISECONDS_BY_INTERVAL = [
        '1m' => 60_000,
        '5m' => 300_000,
        '15m' => 900_000,
        '1h' => 3_600_000,
    ];

    public function normalizedSymbol(string $nativeCoin): string
    {
        return self::NORMALIZED_BY_NATIVE_COIN[$nativeCoin]
            ?? throw new \InvalidArgumentException('hyperliquid_paper_symbol_invalid');
    }

    public function nativeCoin(string $symbol): string
    {
        $nativeCoin = array_search($symbol, self::NORMALIZED_BY_NATIVE_COIN, true);
        if ($nativeCoin === false) {
            throw new \InvalidArgumentException('hyperliquid_paper_symbol_invalid');
        }

        return $nativeCoin;
    }

    public function intervalMilliseconds(string $interval): int
    {
        return self::MILLISECONDS_BY_INTERVAL[$interval]
            ?? throw new \InvalidArgumentException('hyperliquid_paper_interval_invalid');
    }
}
