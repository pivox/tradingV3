<?php

declare(strict_types=1);

namespace App\Common\Enum;

enum Timeframe: string
{
    case TF_1D = '1d';
    case TF_4H = '4h';
    case TF_1H = '1h';
    case TF_30M = '30m';
    case TF_15M = '15m';
    case TF_5M = '5m';
    case TF_1M = '1m';

    public function getStepInMinutes(): int
    {
        return match ($this) {
            self::TF_1D => 1440,
            self::TF_4H => 240,
            self::TF_1H => 60,
            self::TF_30M => 30,
            self::TF_15M => 15,
            self::TF_5M => 5,
            self::TF_1M => 1,
        };
    }

    /** Retourne le Timeframe correspondant au nombre de minutes (ou null si aucun) */
    public static function tryFromMinutes(int $minutes): ?self
    {
        foreach (self::cases() as $tf) {
            if ($tf->getStepInMinutes() === $minutes) {
                return $tf;
            }
        }
        return null;
    }

    /** Variante stricte qui lève une exception si inconnu */
    public static function fromMinutes(int $minutes): self
    {
        return self::tryFromMinutes($minutes)
            ?? throw new \InvalidArgumentException("Aucun Timeframe pour {$minutes} minute(s).");
    }


    public function getStepInSeconds(): int
    {
        return $this->getStepInMinutes() * 60;
    }
}




