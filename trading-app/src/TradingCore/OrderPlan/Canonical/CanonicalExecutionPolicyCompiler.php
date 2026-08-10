<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use App\TradingCore\Config\EffectiveTradingConfigSnapshot;

final class CanonicalExecutionPolicyCompiler
{
    public function compile(EffectiveTradingConfigSnapshot $snapshot): CanonicalExecutionPolicy
    {
        return CanonicalExecutionPolicy::fromSnapshot($snapshot);
    }
}
