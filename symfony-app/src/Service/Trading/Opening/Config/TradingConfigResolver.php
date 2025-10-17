<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\Config;

use App\Service\Trading\Opening\DTO\OpenMarketRequest;
use App\Service\Config\TradingParameters;

final class TradingConfigResolver
{
    public function __construct(private readonly TradingParameters $parameters)
    {
    }

    public function resolve(OpenMarketRequest $request): TradingConfig
    {
        $cfg = $this->parameters->all();

        return new TradingConfig(
            budgetCapUsdt: $request->budgetOverride ?? (float)($cfg['budget']['open_cap_usdt'] ?? 50.0),
            riskAbsUsdt: $request->riskAbsOverride ?? (float)($cfg['risk']['abs_usdt'] ?? 3.0),
            tpAbsUsdt: $request->tpAbsOverride ?? (float)($cfg['tp']['abs_usdt'] ?? 5.0),
            riskPct: (float)($cfg['risk']['pct'] ?? 0.02),
            atrLookback: (int)($cfg['atr']['lookback'] ?? 14),
            atrMethod: (string)($cfg['atr']['method'] ?? 'wilder'),
            atrTimeframe: (string)($cfg['atr']['timeframe'] ?? '15m'),
            atrKStop: (float)($cfg['atr']['k_stop'] ?? 1.5),
            tpRMultiple: (float)($cfg['tp']['r_multiple'] ?? 2.0),
            openType: (string)($cfg['margin']['open_type'] ?? 'isolated')
        );
    }
}
