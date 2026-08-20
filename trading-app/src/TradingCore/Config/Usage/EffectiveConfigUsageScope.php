<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Usage;

enum EffectiveConfigUsageScope: string
{
    case RUN = 'run';
    case SET = 'set';
    case DECISION = 'decision';
    case TRADE = 'trade';

    public function maxIdentifierLength(): int
    {
        return match ($this) {
            self::RUN => 255,
            self::SET, self::TRADE => 96,
            self::DECISION => 36,
        };
    }

    public function requiresUniqueSnapshot(): bool
    {
        return $this === self::DECISION || $this === self::TRADE;
    }
}
