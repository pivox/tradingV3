<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\TradingCore\Config\EffectiveTradingConfigRequest;

interface HyperliquidMutationReadinessConfigSourceInterface
{
    public function forIdentity(EffectiveTradingConfigRequest $identity): HyperliquidMutationReadinessConfig;
}
