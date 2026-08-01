<?php

declare(strict_types=1);

namespace App\Trading\Lineage;

use App\Config\TradeEntryConfig;

final class CanonicalTradeEntryConfigFactory
{
    private const ROOTS = ['schema_version', 'units', 'safety', 'mode', 'setup', 'exchange', 'environment'];

    public static function fromLineage(LineageContext $identity): TradeEntryConfig
    {
        if (!$identity->isModern()) {
            throw new LineageContextException('canonical_identity_required');
        }
        $identity->assertExecutableTradeContract();
        $config = $identity->effectiveConfigSnapshot?->config() ?? [];
        $roots = array_keys($config);
        sort($roots, SORT_STRING);
        $expected = self::ROOTS;
        sort($expected, SORT_STRING);
        if ($roots !== $expected || ($config['schema_version'] ?? null) !== 'effective-trading-config.v2') {
            throw new LineageContextException('canonical_config_invalid:roots');
        }

        $mode = self::mapping($config, 'mode');
        $setup = self::mapping($config, 'setup');
        $exchange = self::mapping($config, 'exchange');
        $environment = self::mapping($config, 'environment');
        if (($mode['mode_id'] ?? null) !== $identity->modeId || ($mode['mode_version'] ?? null) !== $identity->modeVersion
            || ($setup['setup_id'] ?? null) !== $identity->setupId || ($setup['setup_version'] ?? null) !== $identity->setupVersion
            || strtoupper((string) ($setup['side'] ?? '')) !== $identity->side
            || ($exchange['id'] ?? null) !== $identity->exchange || ($environment['id'] ?? null) !== $identity->environment) {
            throw new LineageContextException('canonical_config_mismatch:identity');
        }
        if (($setup['executable'] ?? null) !== true || ($setup['publishable'] ?? null) !== true) {
            throw new LineageContextException('canonical_config_unresolved:setup');
        }
        $safety = self::mapping($config, 'safety');
        if (($safety['require_stop_loss'] ?? null) !== true || ($safety['kill_switch_enabled'] ?? null) !== true
            || ($environment['require_stop_loss'] ?? null) !== true || ($environment['kill_switch_enabled'] ?? null) !== true
            || ($environment['write_enabled'] ?? null) !== false) {
            throw new LineageContextException('canonical_config_invalid:safety');
        }

        $risk = self::mapping($mode, 'risk', 'mode.risk');
        $tradeBudget = self::defined($risk, 'trade_budget', 'mode.risk.trade_budget', 'percent_equity_per_trade');
        $dailyLoss = self::defined($risk, 'daily_loss_cap', 'mode.risk.daily_loss_cap', 'compound_percent_equity_and_quote_per_day');
        $maxPositions = self::defined($risk, 'max_concurrent_positions', 'mode.risk.max_concurrent_positions', 'positions');
        $exposure = self::defined($risk, 'mode_exposure_cap', 'mode.risk.mode_exposure_cap', 'percent_equity_notional');
        $leverage = self::defined($mode, 'leverage', 'mode.leverage', 'leverage_multiple');
        $orderPolicy = self::defined($mode, 'order_policy', 'mode.order_policy', 'policy');
        if (!\is_array($orderPolicy) || !\is_string($orderPolicy['margin_mode'] ?? null) || !\is_string($orderPolicy['preferred_type'] ?? null)) {
            throw new LineageContextException('canonical_config_invalid:mode.order_policy');
        }
        $timeframes = self::mapping($mode, 'timeframes', 'mode.timeframes');
        $executionTfs = $timeframes['execution'] ?? null;
        if (!\is_array($executionTfs) || !array_is_list($executionTfs) || $executionTfs === []) {
            throw new LineageContextException('canonical_config_unresolved:mode.timeframes.execution');
        }

        $ast = self::mapping($setup, 'ast', 'setup.ast');
        $execution = self::mapping($ast, 'execution', 'setup.ast.execution');
        $entryZone = self::defined($execution, 'entry_zone', 'setup.ast.execution.entry_zone', 'price_zone_policy');
        $stop = self::defined($execution, 'stop', 'setup.ast.execution.stop', 'stop_policy');
        $targets = self::defined($execution, 'targets', 'setup.ast.execution.targets', 'target_policy');
        $minimumNetR = self::defined($execution, 'minimum_net_r', 'setup.ast.execution.minimum_net_r', 'net_r_multiple');
        $invalidation = self::defined($execution, 'invalidation', 'setup.ast.execution.invalidation', 'invalidation_policy');
        $timeStop = self::defined($execution, 'time_stop', 'setup.ast.execution.time_stop', 'duration');
        $cost = self::defined($execution, 'cost_contract', 'setup.ast.execution.cost_contract', 'net_cost_model');
        foreach (['entry_zone' => $entryZone, 'stop' => $stop, 'targets' => $targets, 'invalidation' => $invalidation, 'cost_contract' => $cost] as $path => $value) {
            if (!\is_array($value)) {
                throw new LineageContextException('canonical_config_invalid:setup.ast.execution.' . $path);
            }
        }
        if (!\is_numeric($tradeBudget) || !\is_numeric($leverage) || !\is_numeric($minimumNetR)
            || !\is_numeric($targets['r_multiple'] ?? null) || !\is_numeric($stop['atr_k'] ?? null)
            || !\is_string($stop['from'] ?? null) || !\is_numeric($cost['max_spread_rate'] ?? null)
            || !\is_string($timeStop)) {
            throw new LineageContextException('canonical_config_invalid:trade_entry_view');
        }
        $fees = self::mapping($exchange, 'fees', 'exchange.fees');
        if (!\is_numeric($fees['maker_rate'] ?? null) || !\is_numeric($fees['taker_rate'] ?? null)) {
            throw new LineageContextException('canonical_config_invalid:exchange.fees');
        }

        return new TradeEntryConfig(config: [
            'version' => $identity->setupVersion,
            'defaults' => [
                'risk_pct_percent' => (float) $tradeBudget,
                'order_type' => $orderPolicy['preferred_type'],
                'open_type' => $orderPolicy['margin_mode'],
                'stop_from' => $stop['from'],
                'atr_k' => (float) $stop['atr_k'],
                'r_multiple' => (float) $targets['r_multiple'],
                'market_max_spread_pct' => (float) $cost['max_spread_rate'],
            ],
            'entry' => ['entry_zone' => $entryZone, 'time_stop' => $timeStop, 'invalidation' => $invalidation],
            'risk' => ['daily_loss_cap' => $dailyLoss, 'max_concurrent_positions' => $maxPositions, 'mode_exposure_cap' => $exposure, 'minimum_net_r' => $minimumNetR],
            'leverage' => ['canonical_cap' => (float) $leverage],
            'decision' => ['allowed_execution_timeframes' => $executionTfs],
            'fees' => ['maker_rate' => (float) $fees['maker_rate'], 'taker_rate' => (float) $fees['taker_rate']],
        ]);
    }

    /**
     * @param array<string,mixed> $owner
     * @return array<string,mixed>
     */
    private static function mapping(array $owner, string $key, ?string $path = null): array
    {
        $value = $owner[$key] ?? null;
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new LineageContextException('canonical_config_invalid:' . ($path ?? $key));
        }
        return $value;
    }

    /** @param array<string,mixed> $owner */
    private static function defined(array $owner, string $key, string $path, string $unit): mixed
    {
        $decision = self::mapping($owner, $key, $path);
        if (($decision['state'] ?? null) !== 'defined' || ($decision['unit'] ?? null) !== $unit || !array_key_exists('value', $decision) || $decision['value'] === null) {
            throw new LineageContextException('canonical_config_unresolved:' . $path);
        }
        return $decision['value'];
    }
}
