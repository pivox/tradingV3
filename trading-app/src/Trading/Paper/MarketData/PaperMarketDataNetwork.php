<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

enum PaperMarketDataNetwork: string
{
    case MAINNET = 'mainnet';
    case TESTNET = 'testnet';
    case LEGACY_UNKNOWN = 'legacy_unknown';

    public function isCertifiable(): bool
    {
        return $this !== self::LEGACY_UNKNOWN;
    }
}
