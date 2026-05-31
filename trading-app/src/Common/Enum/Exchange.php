<?php

declare(strict_types=1);

namespace App\Common\Enum;

enum Exchange: string
{
    case BITMART = 'bitmart';
    case BINANCE = 'binance';
    case FAKE = 'fake';
    case HYPERLIQUID = 'hyperliquid';

    /**
     * Human readable label for logging/UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::BITMART => 'Bitmart',
            self::BINANCE => 'Binance',
            self::FAKE => 'Fake Exchange',
            self::HYPERLIQUID => 'Hyperliquid',
        };
    }
}
