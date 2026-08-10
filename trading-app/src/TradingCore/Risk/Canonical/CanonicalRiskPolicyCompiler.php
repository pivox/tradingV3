<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical;

use App\TradingCore\Config\EffectiveTradingConfigSnapshot;

final class CanonicalRiskPolicyCompiler
{
    public function compile(EffectiveTradingConfigSnapshot $snapshot): CanonicalRiskPolicy
    {
        return CanonicalRiskPolicy::fromSnapshot($snapshot);
    }
}
