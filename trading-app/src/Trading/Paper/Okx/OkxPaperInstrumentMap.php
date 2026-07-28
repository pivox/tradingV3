<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx;

final readonly class OkxPaperInstrumentMap
{
    /** @var array<string, string> */
    private const NORMALIZED_BY_NATIVE = [
        'BTC-USDT-SWAP' => 'BTCUSDT',
        'ETH-USDT-SWAP' => 'ETHUSDT',
    ];

    /** @return list<string> */
    public function nativeInstrumentIds(): array
    {
        return array_keys(self::NORMALIZED_BY_NATIVE);
    }

    public function normalizedSymbol(string $instrumentId): string
    {
        return self::NORMALIZED_BY_NATIVE[$instrumentId]
            ?? throw new \InvalidArgumentException('okx_paper_instrument_not_allowed');
    }

    public function nativeInstrumentId(string $symbol): string
    {
        $instrumentId = array_search($symbol, self::NORMALIZED_BY_NATIVE, true);
        if ($instrumentId === false) {
            throw new \InvalidArgumentException('okx_paper_instrument_not_allowed');
        }

        return $instrumentId;
    }
}
