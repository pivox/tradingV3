<?php

declare(strict_types=1);

namespace App\Common\Enum;

enum MarketType: string
{
    case PERPETUAL = 'perpetual';
    case SPOT = 'spot';

    public function label(): string
    {
        return match ($this) {
            self::PERPETUAL => 'Perpetual Futures',
            self::SPOT => 'Spot',
        };
    }
}

