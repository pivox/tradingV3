<?php
declare(strict_types=1);

namespace App\TradingCore\Risk\Service;

use App\TradingCore\Risk\Dto\RiskCalculationRequest;
use App\TradingCore\Risk\Dto\RiskCalculationResult;
use App\TradingCore\Risk\Enum\RiskSource;

final class PositionSizer
{
    public function calculate(RiskCalculationRequest $request): RiskCalculationResult
    {
        $stopPct = $this->resolveStopPct($request);
        if ($stopPct <= 0.0 || !\is_finite($stopPct)) {
            throw new \InvalidArgumentException('stopPct must be positive');
        }

        [$effectiveRiskPct, $riskSource, $warnings] = $this->resolveRiskPct($request);
        if ($effectiveRiskPct <= 0.0 || !\is_finite($effectiveRiskPct)) {
            throw new \InvalidArgumentException('effective risk pct must be positive');
        }

        $capitalBase = $this->resolveCapitalBase($request, $effectiveRiskPct);
        if ($capitalBase <= 0.0 || !\is_finite($capitalBase)) {
            throw new \InvalidArgumentException('capital base must be positive');
        }

        $riskUsdt = $capitalBase * $effectiveRiskPct;
        $positionNotional = $riskUsdt / $stopPct;
        $quantity = $request->entryPrice > 0.0 ? $positionNotional / $request->entryPrice : null;

        return new RiskCalculationResult(
            effectiveRiskPct: $effectiveRiskPct,
            riskSource: $riskSource,
            riskUsdt: $riskUsdt,
            stopPct: $stopPct,
            positionNotional: $positionNotional,
            quantity: $quantity,
            warnings: $warnings,
            metadata: [
                'capital_base_usdt' => $capitalBase,
                'runtime_note' => 'Legacy TradeEntry currently sizes contracts from riskUsdt / stop distance, then exchange volume clamps are applied in OrderPlanBuilder.',
            ],
        );
    }

    private function resolveStopPct(RiskCalculationRequest $request): float
    {
        if ($request->stopPct !== null) {
            return $request->stopPct;
        }

        if ($request->stopPrice !== null && $request->entryPrice > 0.0) {
            return abs($request->entryPrice - $request->stopPrice) / $request->entryPrice;
        }

        return 0.0;
    }

    /**
     * @return array{0:float,1:RiskSource,2:list<string>}
     */
    private function resolveRiskPct(RiskCalculationRequest $request): array
    {
        $warnings = [];
        $legacyRuntimeSource = $request->metadata['legacy_runtime_risk_source'] ?? null;
        if (
            $legacyRuntimeSource === RiskSource::RiskPctPercentLegacy
            && $request->riskPctPercentLegacy !== null
            && $request->riskPctPercentLegacy > 0.0
        ) {
            if ($request->fixedRiskPct !== null && $request->fixedRiskPct > 0.0) {
                $warnings[] = 'Preserving legacy runtime risk source from defaults.risk_pct_percent; risk.fixed_risk_pct is carried for audit only.';
            }

            return [$request->riskPctPercentLegacy, RiskSource::RiskPctPercentLegacy, $warnings];
        }

        if ($request->fixedRiskPct !== null && $request->fixedRiskPct > 0.0) {
            if ($request->riskPctPercentLegacy !== null && $request->riskPctPercentLegacy > 0.0) {
                $warnings[] = 'Both fixedRiskPct and legacy riskPctPercent are present; fixedRiskPct is the canonical module source.';
            }

            return [$request->fixedRiskPct, RiskSource::FixedRiskPct, $warnings];
        }

        if ($request->riskPctPercentLegacy !== null && $request->riskPctPercentLegacy > 0.0) {
            $warnings[] = 'Using legacy defaults.risk_pct_percent because fixedRiskPct is absent.';

            return [$request->riskPctPercentLegacy, RiskSource::RiskPctPercentLegacy, $warnings];
        }

        throw new \InvalidArgumentException('risk pct source is required');
    }

    private function resolveCapitalBase(RiskCalculationRequest $request, float $riskPct): float
    {
        $initialMargin = $request->initialMarginUsdt;
        if ($initialMargin !== null && $initialMargin > 0.0) {
            if ($request->availableBalance !== null && $request->availableBalance > 0.0) {
                return min($initialMargin, $request->availableBalance);
            }

            return $initialMargin;
        }

        if ($request->fallbackAccountBalance !== null && $request->fallbackAccountBalance > 0.0) {
            // Mirrors TradeEntryRequestBuilder: initialMargin = fallbackCapital * riskPct.
            // Caller applies effectiveRiskPct again → riskUsdt = balance * riskPct².
            // All current YAML configs set fallback_account_balance: 0.0 so this path is unreachable in production.
            return $request->fallbackAccountBalance * $riskPct;
        }

        if ($request->availableBalance !== null && $request->availableBalance > 0.0) {
            return $request->availableBalance;
        }

        return $request->equity !== null && $request->equity > 0.0 ? $request->equity : 0.0;
    }
}
