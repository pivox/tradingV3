<?php

declare(strict_types=1);

namespace App\Common\Enum;

enum SignalSide: string
{
    case LONG = 'LONG';
    case SHORT = 'SHORT';
    case NONE = 'NONE';

    public function isLong(): bool
    {
        return $this === self::LONG;
    }

    public function isShort(): bool
    {
        return $this === self::SHORT;
    }

    public function isNone(): bool
    {
        return $this === self::NONE;
    }

    public function getOpposite(): self
    {
        return match ($this) {
            self::LONG => self::SHORT,
            self::SHORT => self::LONG,
            self::NONE => self::NONE,
        };
    }

    public function toOrderSide(): string
    {
        return match ($this) {
            self::LONG => 'buy',
            self::SHORT => 'sell',
            self::NONE => throw new \InvalidArgumentException('Cannot convert NONE signal to order side'),
        };
    }
}




