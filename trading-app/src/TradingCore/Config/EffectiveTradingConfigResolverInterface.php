<?php

declare(strict_types=1);

namespace App\TradingCore\Config;

interface EffectiveTradingConfigResolverInterface
{
    public function resolve(EffectiveTradingConfigRequest|string $request): EffectiveTradingConfigSnapshot;
}
