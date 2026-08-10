<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical;

use App\TradingCore\Config\EffectiveTradingConfigSnapshot;

final class CanonicalRiskPolicyCompiler
{
    public function compile(EffectiveTradingConfigSnapshot $snapshot): CanonicalRiskPolicy
    {
        if (!$snapshot->executable || $snapshot->blockers !== []) {
            throw new CanonicalRiskException('canonical_policy_snapshot_not_executable');
        }

        $payload = $snapshot->payload();
        $mode = $this->mapping($payload, 'mode', 'canonical_policy_shape_invalid');
        $setup = $this->mapping($payload, 'setup', 'canonical_policy_shape_invalid');
        $exchange = $this->mapping($payload, 'exchange', 'canonical_policy_shape_invalid');
        $environment = $this->mapping($payload, 'environment', 'canonical_policy_shape_invalid');
        $safety = $this->mapping($payload, 'safety', 'canonical_policy_shape_invalid');
        $request = $snapshot->request;

        if (
            ($mode['mode_id'] ?? null) !== $request->modeId
            || ($mode['mode_version'] ?? null) !== $request->modeVersion
            || ($setup['setup_id'] ?? null) !== $request->setupId
            || ($setup['setup_version'] ?? null) !== $request->setupVersion
            || ($setup['side'] ?? null) !== $request->side
            || ($exchange['id'] ?? null) !== $request->exchange
            || ($environment['id'] ?? null) !== $request->environment
        ) {
            throw new CanonicalRiskException('canonical_policy_identity_mismatch');
        }

        if (
            ($safety['mainnet_write_enabled'] ?? null) !== false
            || ($safety['demo_testnet_write_enabled'] ?? null) !== false
            || ($safety['require_stop_loss'] ?? null) !== true
            || ($safety['kill_switch_enabled'] ?? null) !== true
            || ($environment['write_enabled'] ?? null) !== false
            || ($environment['require_stop_loss'] ?? null) !== true
            || ($environment['kill_switch_enabled'] ?? null) !== true
        ) {
            throw new CanonicalRiskException('canonical_policy_safety_invalid');
        }

        $risk = $this->mapping($mode, 'risk', 'canonical_policy_shape_invalid');
        foreach (['risk_pct', 'risk_pct_percent', 'fixed_risk_pct', 'initial_margin_usdt'] as $legacyKey) {
            if (array_key_exists($legacyKey, $risk)) {
                throw new CanonicalRiskException('canonical_policy_legacy_risk_source_forbidden', ['key' => $legacyKey]);
            }
        }

        $tradeBudget = $this->mapping($risk, 'trade_budget', 'canonical_policy_trade_budget_unresolved');
        if (($tradeBudget['state'] ?? null) !== 'defined' || !array_key_exists('value', $tradeBudget) || $tradeBudget['value'] === null) {
            throw new CanonicalRiskException('canonical_policy_trade_budget_unresolved');
        }
        if (($tradeBudget['unit'] ?? null) !== 'percent_equity_per_trade') {
            throw new CanonicalRiskException('canonical_policy_trade_budget_unit_invalid');
        }
        $tradeBudgetPercent = $this->finiteNumber($tradeBudget['value'] ?? null, 'canonical_policy_trade_budget_value_invalid');
        if ($tradeBudgetPercent <= 0.0 || $tradeBudgetPercent > 100.0) {
            throw new CanonicalRiskException('canonical_policy_trade_budget_value_invalid');
        }
        $riskRate = $tradeBudgetPercent / 100.0;

        $leverage = $this->mapping($mode, 'leverage', 'canonical_policy_mode_leverage_unresolved');
        if (($leverage['state'] ?? null) !== 'defined' || ($leverage['unit'] ?? null) !== 'leverage_multiple' || !array_key_exists('value', $leverage)) {
            throw new CanonicalRiskException('canonical_policy_mode_leverage_unresolved');
        }
        $modeLeverageCap = $this->finiteNumber($leverage['value'], 'canonical_policy_mode_leverage_invalid');
        if ($modeLeverageCap < 1.0) {
            throw new CanonicalRiskException('canonical_policy_mode_leverage_invalid');
        }

        $fees = $this->mapping($exchange, 'fees', 'canonical_policy_fee_rate_invalid');
        $makerFeeRate = $this->rate($fees['maker_rate'] ?? null);
        $takerFeeRate = $this->rate($fees['taker_rate'] ?? null);

        $limits = $this->mapping($exchange, 'limits', 'canonical_policy_notional_cap_invalid');
        $exchangeMaxNotional = $this->positiveNumber($limits['max_notional'] ?? null, 'canonical_policy_notional_cap_invalid');
        $environmentMaxNotional = $this->positiveNumber($environment['max_notional'] ?? null, 'canonical_policy_notional_cap_invalid');

        return new CanonicalRiskPolicy(
            modeId: $request->modeId,
            modeVersion: $request->modeVersion,
            setupId: $request->setupId,
            setupVersion: $request->setupVersion,
            exchange: $request->exchange,
            environment: $request->environment,
            side: $request->side,
            configHash: $snapshot->configHash,
            riskRate: $riskRate,
            modeLeverageCap: $modeLeverageCap,
            makerFeeRate: $makerFeeRate,
            takerFeeRate: $takerFeeRate,
            exchangeMaxNotional: $exchangeMaxNotional,
            environmentMaxNotional: $environmentMaxNotional,
        );
    }

    /**
     * @param array<string, mixed> $owner
     * @return array<string, mixed>
     */
    private function mapping(array $owner, string $key, string $reasonCode): array
    {
        $value = $owner[$key] ?? null;
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new CanonicalRiskException($reasonCode);
        }

        return $value;
    }

    private function finiteNumber(mixed $value, string $reasonCode): float
    {
        if ((!\is_int($value) && !\is_float($value)) || !\is_finite((float) $value)) {
            throw new CanonicalRiskException($reasonCode);
        }

        return (float) $value;
    }

    private function positiveNumber(mixed $value, string $reasonCode): float
    {
        $number = $this->finiteNumber($value, $reasonCode);
        if ($number <= 0.0) {
            throw new CanonicalRiskException($reasonCode);
        }

        return $number;
    }

    private function rate(mixed $value): float
    {
        $rate = $this->finiteNumber($value, 'canonical_policy_fee_rate_invalid');
        if ($rate < 0.0 || $rate >= 1.0) {
            throw new CanonicalRiskException('canonical_policy_fee_rate_invalid');
        }

        return $rate;
    }
}
