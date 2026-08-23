<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Normalization;

final class HyperliquidOrderNotionalLimits
{
    public const MODEL = 'hyperliquid-max-order-notional-by-leverage.v1';

    public static function maximumMarketNotional(int $maximumLeverage): string
    {
        if ($maximumLeverage < 1) {
            throw new \InvalidArgumentException('hyperliquid_order_notional_leverage_invalid');
        }

        return match (true) {
            $maximumLeverage >= 25 => '15000000',
            $maximumLeverage >= 20 => '5000000',
            $maximumLeverage >= 10 => '2000000',
            default => '500000',
        };
    }

    public static function maximumLimitNotional(int $maximumLeverage): string
    {
        return (string) ((int) self::maximumMarketNotional($maximumLeverage) * 10);
    }
}
