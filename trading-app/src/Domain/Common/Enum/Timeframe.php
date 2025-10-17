<?php

declare(strict_types=1);

namespace App\Domain\Common\Enum;

enum Timeframe: string
{
    case TF_4H = '4h';
    case TF_1H = '1h';
    case TF_15M = '15m';
    case TF_5M = '5m';
    case TF_1M = '1m';

    public function getStepInMinutes(): int
    {
        return match ($this) {
            self::TF_4H => 240,
            self::TF_1H => 60,
            self::TF_15M => 15,
            self::TF_5M => 5,
            self::TF_1M => 1,
        };
    }

    public function getStepInSeconds(): int
    {
        return $this->getStepInMinutes() * 60;
    }

    public function isHigherTimeframe(Timeframe $other): bool
    {
        return $this->getStepInMinutes() > $other->getStepInMinutes();
    }

    public function isLowerTimeframe(Timeframe $other): bool
    {
        return $this->getStepInMinutes() < $other->getStepInMinutes();
    }

    /**
     * @return Timeframe[]
     */
    public static function getHigherTimeframes(): array
    {
        return [self::TF_4H, self::TF_1H];
    }

    /**
     * @return Timeframe[]
     */
    public static function getLowerTimeframes(): array
    {
        return [self::TF_5M, self::TF_1M];
    }

    /**
     * @return Timeframe[]
     */
    public static function getExecutionTimeframes(): array
    {
        return [self::TF_5M, self::TF_1M];
    }
}




