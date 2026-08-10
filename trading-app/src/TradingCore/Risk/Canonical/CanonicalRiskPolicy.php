<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;

final readonly class CanonicalRiskPolicy
{
    private function __construct(
        public string $modeId,
        public string $modeVersion,
        public string $setupId,
        public string $setupVersion,
        public string $exchange,
        public string $environment,
        public string $side,
        public string $configHash,
        public float $riskRate,
        public float $modeLeverageCap,
        public float $makerFeeRate,
        public float $takerFeeRate,
        public float $exchangeMinNotional,
        public float $exchangeMaxNotional,
        public float $environmentMaxNotional,
    ) {
    }

    public static function fromSnapshot(EffectiveTradingConfigSnapshot $snapshot): self
    {
        if (!$snapshot->executable || $snapshot->blockers !== []) {
            throw new CanonicalRiskException('canonical_policy_snapshot_not_executable');
        }
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $snapshot->configHash) !== 1) {
            throw new CanonicalRiskException('canonical_policy_hash_invalid');
        }
        if (!\is_string($snapshot->conditionCatalogHash) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $snapshot->conditionCatalogHash) !== 1) {
            throw new CanonicalRiskException('canonical_policy_hash_invalid');
        }

        $payload = $snapshot->payload();
        $mode = self::mapping($payload, 'mode', 'canonical_policy_shape_invalid');
        $setup = self::mapping($payload, 'setup', 'canonical_policy_shape_invalid');
        $exchange = self::mapping($payload, 'exchange', 'canonical_policy_shape_invalid');
        $environment = self::mapping($payload, 'environment', 'canonical_policy_shape_invalid');
        $safety = self::mapping($payload, 'safety', 'canonical_policy_shape_invalid');
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

        foreach ([
            [$safety, 'mainnet_write_enabled', false],
            [$safety, 'demo_testnet_write_enabled', false],
            [$safety, 'require_stop_loss', true],
            [$safety, 'kill_switch_enabled', true],
            [$environment, 'write_enabled', false],
            [$environment, 'require_stop_loss', true],
            [$environment, 'kill_switch_enabled', true],
        ] as [$owner, $key, $expected]) {
            if (!array_key_exists($key, $owner) || $owner[$key] !== $expected) {
                throw new CanonicalRiskException('canonical_policy_safety_invalid');
            }
        }

        $risk = self::mapping($mode, 'risk', 'canonical_policy_shape_invalid');
        foreach (['risk_pct', 'risk_pct_percent', 'fixed_risk_pct', 'initial_margin_usdt'] as $legacyKey) {
            if (array_key_exists($legacyKey, $risk)) {
                throw new CanonicalRiskException('canonical_policy_legacy_risk_source_forbidden', ['key' => $legacyKey]);
            }
        }

        $tradeBudget = self::mapping($risk, 'trade_budget', 'canonical_policy_trade_budget_unresolved');
        if (($tradeBudget['state'] ?? null) !== 'defined' || !array_key_exists('value', $tradeBudget) || $tradeBudget['value'] === null) {
            throw new CanonicalRiskException('canonical_policy_trade_budget_unresolved');
        }
        if (($tradeBudget['unit'] ?? null) !== 'percent_equity_per_trade') {
            throw new CanonicalRiskException('canonical_policy_trade_budget_unit_invalid');
        }
        $tradeBudgetPercent = self::finiteNumber($tradeBudget['value'], 'canonical_policy_trade_budget_value_invalid');
        if ($tradeBudgetPercent <= 0.0 || $tradeBudgetPercent > 100.0) {
            throw new CanonicalRiskException('canonical_policy_trade_budget_value_invalid');
        }
        $riskRate = $tradeBudgetPercent / 100.0;

        $leverage = self::mapping($mode, 'leverage', 'canonical_policy_mode_leverage_unresolved');
        if (($leverage['state'] ?? null) !== 'defined' || ($leverage['unit'] ?? null) !== 'leverage_multiple' || !array_key_exists('value', $leverage)) {
            throw new CanonicalRiskException('canonical_policy_mode_leverage_unresolved');
        }
        $modeLeverageCap = self::finiteNumber($leverage['value'], 'canonical_policy_mode_leverage_invalid');
        if ($modeLeverageCap < 1.0) {
            throw new CanonicalRiskException('canonical_policy_mode_leverage_invalid');
        }

        $fees = self::mapping($exchange, 'fees', 'canonical_policy_fee_rate_invalid');
        $makerFeeRate = self::rate($fees['maker_rate'] ?? null);
        $takerFeeRate = self::rate($fees['taker_rate'] ?? null);

        $limits = self::mapping($exchange, 'limits', 'canonical_policy_notional_cap_invalid');
        $exchangeMinNotional = self::nonNegativeNumber($limits['min_notional'] ?? null, 'canonical_policy_notional_cap_invalid');
        $exchangeMaxNotional = self::positiveNumber($limits['max_notional'] ?? null, 'canonical_policy_notional_cap_invalid');
        $environmentMaxNotional = self::positiveNumber($environment['max_notional'] ?? null, 'canonical_policy_notional_cap_invalid');
        if ($exchangeMinNotional > $exchangeMaxNotional) {
            throw new CanonicalRiskException('canonical_policy_notional_cap_invalid');
        }
        $expectedConfigHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $snapshot->conditionCatalogHash);
        if (!hash_equals($expectedConfigHash, $snapshot->configHash)) {
            throw new CanonicalRiskException('canonical_policy_hash_mismatch');
        }

        return new self(
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
            exchangeMinNotional: $exchangeMinNotional,
            exchangeMaxNotional: $exchangeMaxNotional,
            environmentMaxNotional: $environmentMaxNotional,
        );
    }

    /**
     * @param array<string, mixed> $owner
     * @return array<string, mixed>
     */
    private static function mapping(array $owner, string $key, string $reasonCode): array
    {
        $value = $owner[$key] ?? null;
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new CanonicalRiskException($reasonCode);
        }

        return $value;
    }

    private static function finiteNumber(mixed $value, string $reasonCode): float
    {
        if ((!\is_int($value) && !\is_float($value)) || !\is_finite((float) $value)) {
            throw new CanonicalRiskException($reasonCode);
        }

        return (float) $value;
    }

    private static function positiveNumber(mixed $value, string $reasonCode): float
    {
        $number = self::finiteNumber($value, $reasonCode);
        if ($number <= 0.0) {
            throw new CanonicalRiskException($reasonCode);
        }

        return $number;
    }

    private static function nonNegativeNumber(mixed $value, string $reasonCode): float
    {
        $number = self::finiteNumber($value, $reasonCode);
        if ($number < 0.0) {
            throw new CanonicalRiskException($reasonCode);
        }

        return $number;
    }

    private static function rate(mixed $value): float
    {
        $rate = self::finiteNumber($value, 'canonical_policy_fee_rate_invalid');
        if ($rate < 0.0 || $rate >= 1.0) {
            throw new CanonicalRiskException('canonical_policy_fee_rate_invalid');
        }

        return $rate;
    }
}
