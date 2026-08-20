<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Audit;

final class EffectiveConfigHash
{
    public static function require(string $hash): string
    {
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new \InvalidArgumentException('effective_config_hash_invalid');
        }

        return $hash;
    }
}
