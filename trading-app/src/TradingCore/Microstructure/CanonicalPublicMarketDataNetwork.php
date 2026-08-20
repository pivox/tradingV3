<?php

declare(strict_types=1);

namespace App\TradingCore\Microstructure;

final class CanonicalPublicMarketDataNetwork
{
    public static function forTarget(?string $exchange, ?string $environment): ?string
    {
        if (!\in_array($exchange, ['okx', 'hyperliquid'], true)) {
            return null;
        }
        if (\in_array($environment, ['mainnet', 'testnet'], true)) {
            return $environment;
        }

        return $exchange === 'okx' && $environment === 'demo' ? 'mainnet' : null;
    }
}
