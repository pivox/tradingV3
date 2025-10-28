<?php
declare(strict_types=1);

namespace App\TradeEntry\PreOrder;

use App\TradeEntry\Dto\TradeEntryRequest;
 
final class PreOrderBuilder
{
    public function __construct(
        private array $defaults = []
    ) {}

    public function build(array $input): TradeEntryRequest
    {
        // Ici on mappe simplement le tableau → DTO (validation minimale).
        return new TradeEntryRequest(
            symbol:           (string)$input['symbol'],
            side:             $input['side'],
            entryPriceBase:   (float)$input['entry_price_base'],
            atrValue:         (float)$input['atr_value'],
            pivotPrice:       (float)$input['pivot_price'],
            riskPct:          (float)$input['risk_pct'],
            budgetUsdt:       (float)$input['budget_usdt'],
            equityUsdt:       (float)$input['equity_usdt'],
            rsi:              $input['rsi'] ?? null,
            volumeRatio:      $input['volume_ratio'] ?? null,
            pullbackConfirmed:$input['pullback_confirmed'] ?? null,
            tickSize:         (float)($input['tick_size'] ?? $this->defaults['tick_size'] ?? 0.1),
            zoneTtlSec:       (int)($input['zone_ttl_sec'] ?? $this->defaults['zone_ttl_sec'] ?? 240),
            kLow:             (float)($input['k_low'] ?? $this->defaults['k_low'] ?? 1.2),
            kHigh:            (float)($input['k_high'] ?? $this->defaults['k_high'] ?? 0.4),
            kStopAtr:         (float)($input['k_stop_atr'] ?? $this->defaults['k_stop_atr'] ?? 1.5),
            tp1R:             (float)($input['tp1_r'] ?? $this->defaults['tp1_r'] ?? 2.0),
            tp1SizePct:       (int)($input['tp1_size_pct'] ?? $this->defaults['tp1_size_pct'] ?? 60),
            levMin:           (float)($input['lev_min'] ?? $this->defaults['lev_min'] ?? 2.0),
            levMax:           (float)($input['lev_max'] ?? $this->defaults['lev_max'] ?? 20.0),
            kDynamic:         (float)($input['k_dynamic'] ?? $this->defaults['k_dynamic'] ?? 10.0),
            rsiCap:           (float)($input['rsi_cap'] ?? $this->defaults['rsi_cap'] ?? 70.0),
            requirePullback:  (bool)($input['require_pullback'] ?? $this->defaults['require_pullback'] ?? true),
            minVolumeRatio:   (float)($input['min_volume_ratio'] ?? $this->defaults['min_volume_ratio'] ?? 1.5),
        );
    }
}
