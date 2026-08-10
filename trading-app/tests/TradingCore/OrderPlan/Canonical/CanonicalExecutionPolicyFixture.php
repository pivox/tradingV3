<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan\Canonical;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;

final class CanonicalExecutionPolicyFixture
{
    public static function policy(string $side = 'long', string $stopKind = 'atr', string $timeStop = 'PT30M'): CanonicalExecutionPolicy
    {
        $setupId = 'day_trading.trend_continuation.' . $side;
        $payload = self::payload($side, $stopKind, $timeStop);
        $catalogHash = 'sha256:' . str_repeat('b', 64);
        $snapshot = new EffectiveTradingConfigSnapshot(
            new EffectiveTradingConfigRequest('day_trading', '1.0.0', $setupId, '1.0.0', 'fake', 'test', $side),
            $payload,
            CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $catalogHash),
            $catalogHash,
            [],
            [],
        );

        return (new CanonicalExecutionPolicyCompiler())->compile($snapshot);
    }

    /** @return array<string, mixed> */
    public static function payload(string $side = 'long', string $stopKind = 'atr', string $timeStop = 'PT30M'): array
    {
        $decision = static fn (mixed $value, string $unit): array => [
            'state' => 'defined',
            'value' => $value,
            'unit' => $unit,
            'source' => 'test-fixture',
            'justification' => 'canonical Lot B test fixture',
        ];
        $setupId = 'day_trading.trend_continuation.' . $side;
        $setup = [
            'schema_version' => 'compiled-setup.v1',
            'setup_id' => $setupId,
            'setup_version' => '1.0.0',
            'status' => 'paper',
            'executable' => true,
            'publishable' => true,
            'family' => 'trend_continuation',
            'side' => $side,
            'thesis' => 'synthetic thesis',
            'hypothesis' => 'synthetic hypothesis',
            'mode_versions' => ['day_trading' => '1.0.0'],
            'mode_compatibility' => ['state' => 'defined'],
            'ast' => [
                'kind' => 'setup',
                'side' => $side,
                'regime' => ['op' => 'all_of', 'nodes' => []],
                'context' => ['op' => 'all_of', 'nodes' => []],
                'trigger' => ['op' => 'all_of', 'nodes' => []],
                'confirmations' => ['op' => 'all_of', 'nodes' => []],
                'filters' => ['op' => 'all_of', 'nodes' => []],
                'no_trade_rules' => ['op' => 'all_of', 'nodes' => []],
                'execution' => [
                    'side' => $side,
                    'entry_zone' => $decision([
                        'anchor_source' => 'vwap',
                        'anchor_timeframe' => '5m',
                        'atr_timeframe' => '5m',
                        'atr_multiplier' => 0.5,
                        'minimum_half_width_rate' => 0.001,
                        'maximum_half_width_rate' => 0.01,
                        'asymmetry_rate' => 0.2,
                        'ttl_seconds' => 180,
                        'maximum_input_age_seconds' => 60,
                        'quantize_outward' => true,
                    ], 'price_zone_policy'),
                    'stop' => $decision([
                        'kind' => $stopKind,
                        'timeframe' => '5m',
                        'atr_multiplier' => $stopKind === 'atr' ? 1.5 : null,
                        'pivot_id' => $stopKind === 'pivot' ? 's1' : null,
                        'buffer_rate' => 0.001,
                    ], 'stop_policy'),
                    'targets' => $decision([
                        ['id' => 'tp1', 'risk_multiple' => 1.5, 'liquidity_role' => 'taker'],
                        ['id' => 'tp2', 'risk_multiple' => 2.0, 'liquidity_role' => 'taker'],
                    ], 'target_policy'),
                    'minimum_net_r' => $decision(1.2, 'net_r_multiple'),
                    'invalidation' => $decision(['kind' => 'close_beyond_stop'], 'invalidation_policy'),
                    'time_stop' => $decision($timeStop, 'duration'),
                    'cost_contract' => $decision([
                        'entry_spread_source' => 'order_book',
                        'entry_slippage_source' => 'execution_model',
                        'stop_spread_source' => 'order_book',
                        'stop_slippage_source' => 'execution_model',
                        'target_spread_source' => 'order_book',
                        'target_slippage_source' => 'execution_model',
                        'funding_source' => 'venue_schedule',
                        'funding_interval_seconds' => 28_800,
                    ], 'cost_policy'),
                ],
            ],
            'missing_data_policy' => ['absent' => 'reject', 'stale' => 'reject', 'critical' => 'reject'],
            'data_condition_contract' => [
                'required_data' => ['ohlcv'],
                'missing_conditions' => [],
                'external_dependencies' => [],
                'condition_catalog_hash' => $decision(str_repeat('b', 64), 'sha256'),
                'unknown_condition_policy' => 'reject',
            ],
            'validity_window' => ['state' => 'defined'],
            'governance' => ['activation_requires_trace' => true],
            'known_defects' => [],
            'ownership_model' => 'setup-contract-ownership-v1',
            'source_origins' => [['file' => 'synthetic.yaml', 'line_range' => '1-10', 'content_sha256' => str_repeat('e', 64), 'commit' => str_repeat('f', 40)]],
            'contract_provenance' => ['context.trigger' => 'synthetic.yaml:1-10'],
            'contract_hash' => str_repeat('d', 64),
            'condition_catalog_hash' => str_repeat('b', 64),
            'blockers' => [],
        ];
        $setup['payload_hash'] = self::canonicalHash($setup);

        return [
            'schema_version' => 'effective-trading-config.v2',
            'units' => ['percent' => 'percentage_points', 'duration' => 'iso8601', 'price' => 'quote_price', 'notional' => 'quote_notional'],
            'safety' => ['mainnet_write_enabled' => false, 'demo_testnet_write_enabled' => false, 'require_stop_loss' => true, 'kill_switch_enabled' => true],
            'mode' => [
                'mode_id' => 'day_trading',
                'mode_version' => '1.0.0',
                'risk' => ['trade_budget' => ['state' => 'defined', 'value' => 1.0, 'unit' => 'percent_equity_per_trade']],
                'leverage' => ['state' => 'defined', 'value' => 5.0, 'unit' => 'leverage_multiple'],
            ],
            'setup' => $setup,
            'exchange' => ['id' => 'fake', 'fees' => ['maker_rate' => 0.0002, 'taker_rate' => 0.0005], 'limits' => ['min_notional' => 5.0, 'max_notional' => 1000.0]],
            'environment' => ['id' => 'test', 'max_notional' => 250.0, 'write_enabled' => false, 'kill_switch_enabled' => true, 'require_stop_loss' => true],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function rehashSetup(array $payload): array
    {
        unset($payload['setup']['payload_hash']);
        $payload['setup']['payload_hash'] = self::canonicalHash($payload['setup']);

        return $payload;
    }

    /** @param array<string, mixed> $value */
    private static function canonicalHash(array $value): string
    {
        $canonicalize = static function (mixed $node) use (&$canonicalize): mixed {
            if (!\is_array($node)) {
                return $node;
            }
            if (!array_is_list($node)) {
                ksort($node, SORT_STRING);
            }
            foreach ($node as $key => $child) {
                $node[$key] = $canonicalize($child);
            }

            return $node;
        };

        return hash('sha256', json_encode($canonicalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
