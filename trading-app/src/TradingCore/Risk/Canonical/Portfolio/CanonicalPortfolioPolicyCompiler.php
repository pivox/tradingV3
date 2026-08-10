<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

use App\TradingCore\Config\EffectiveTradingConfigSnapshot;

final class CanonicalPortfolioPolicyCompiler
{
    public function compile(EffectiveTradingConfigSnapshot $snapshot): CanonicalPortfolioPolicy
    {
        return CanonicalPortfolioPolicy::fromSnapshot($snapshot);
    }
}
