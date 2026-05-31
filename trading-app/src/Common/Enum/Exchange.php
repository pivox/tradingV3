<?php

declare(strict_types=1);

namespace App\Common\Enum;

enum Exchange: string
{
    case BITMART = 'bitmart';
    case BINANCE = 'binance';

    /**
     * Human readable label for logging/UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::BITMART => 'Bitmart',
            self::BINANCE => 'Binance',
        };
    }
}
