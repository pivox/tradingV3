<?php
declare(strict_types=1);

namespace App\TradingCore\Risk\Service;

use App\TradingCore\Risk\Dto\RiskCalculationRequest;
use App\TradingCore\Risk\Enum\RiskSource;

final class RiskConfigInterpreter
{
    /**
     * @param array<string,mixed> $defaults Legacy trade_entry.defaults block.
     * @param array<string,mixed> $risk Legacy trade_entry.risk block.
     */
    public function fromLegacyTradeEntryConfig(
        string $symbol,
        string $profile,
        string $exchange,
        string $marketType,
        float $entryPrice,
        ?float $stopPct,
        array $defaults,
        array $risk,
        ?string $instrument = null,
        ?float $equity = null,
        ?float $availableBalance = null,
        ?float $stopPrice = null,
    ): RiskCalculationRequest {
        $warnings = [];

        $legacyRiskPct = null;
        if (isset($defaults['risk_pct_percent']) && is_numeric($defaults['risk_pct_percent'])) {
            $legacyRiskPct = $this->normalizeLegacyPercent((float)$defaults['risk_pct_percent']);
        }

        $fixedRiskPct = null;
        if (isset($risk['fixed_risk_pct']) && is_numeric($risk['fixed_risk_pct'])) {
            $fixedRiskPct = $this->normalizePercent((float)$risk['fixed_risk_pct']);
        }

        $runtimeSource = null;
        if ($legacyRiskPct !== null) {
            $runtimeSource = RiskSource::RiskPctPercentLegacy;
            if ($fixedRiskPct !== null) {
                $warnings[] = 'risk.fixed_risk_pct is configured but legacy TradeEntry runtime derives TradeEntryRequest::riskPct from defaults.risk_pct_percent.';
            }
        } elseif ($fixedRiskPct !== null) {
            $runtimeSource = RiskSource::FixedRiskPct;
        }

        $initialMargin = isset($defaults['initial_margin_usdt']) && is_numeric($defaults['initial_margin_usdt'])
            ? (float)$defaults['initial_margin_usdt']
            : null;
        $fallbackAccountBalance = isset($defaults['fallback_account_balance']) && is_numeric($defaults['fallback_account_balance'])
            ? (float)$defaults['fallback_account_balance']
            : null;

        return new RiskCalculationRequest(
            symbol: $symbol,
            instrument: $instrument,
            profile: $profile,
            exchange: $exchange,
            marketType: $marketType,
            equity: $equity,
            availableBalance: $availableBalance,
            entryPrice: $entryPrice,
            stopPrice: $stopPrice,
            stopPct: $stopPct,
            fixedRiskPct: $fixedRiskPct,
            riskPctPercentLegacy: $legacyRiskPct,
            initialMarginUsdt: $initialMargin,
            fallbackAccountBalance: $fallbackAccountBalance,
            metadata: [
                'legacy_runtime_risk_source' => $runtimeSource,
                'warnings' => $warnings,
            ],
        );
    }

    public function normalizePercent(float $value): float
    {
        $value = max(0.0, $value);
        if ($value > 1.0) {
            $value *= 0.01;
        }

        return min($value, 1.0);
    }

    private function normalizeLegacyPercent(float $value): float
    {
        $value = max(0.0, $value);

        return min($value * 0.01, 1.0);
    }
}
