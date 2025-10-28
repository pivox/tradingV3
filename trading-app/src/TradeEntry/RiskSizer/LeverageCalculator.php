<?php
declare(strict_types=1);

namespace App\TradeEntry\RiskSizer;

 
final class LeverageCalculator
{
    /**
     * leverage = risk_usdt / (stop_pct * budget_usdt)
     * puis bornes : min/max et dynamique min(kDynamic/stop_pct, max)
     */
    public function compute(float $riskUsdt, float $stopPct, float $budgetUsdt, float $levMin, float $levMax, float $kDynamic): float
    {
        $base = $riskUsdt / max(1e-9, ($stopPct * max(1e-9, $budgetUsdt)));
        $dynCap = min($levMax, $kDynamic / max(1e-9, $stopPct));
        return max($levMin, min($base, $dynCap));
    }
}
