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
    public static function policy(string $side = 'long'): CanonicalExecutionPolicy
    {
        $decision = static fn (mixed $value, string $unit): array => [
            'state' => 'defined',
            'value' => $value,
            'unit' => $unit,
            'source' => 'test-fixture',
            'justification' => 'canonical Lot B test fixture',
        ];
        $setupId = 'day_trading.trend_continuation.' . $side;
        $payload = [
            'schema_version' => 'effective-trading-config.v2',
            'units' => ['percent' => 'percentage_points', 'duration' => 'iso8601', 'price' => 'quote_price', 'notional' => 'quote_notional'],
            'safety' => ['mainnet_write_enabled' => false, 'demo_testnet_write_enabled' => false, 'require_stop_loss' => true, 'kill_switch_enabled' => true],
            'mode' => [
                'mode_id' => 'day_trading',
                'mode_version' => '1.0.0',
                'risk' => ['trade_budget' => ['state' => 'defined', 'value' => 1.0, 'unit' => 'percent_equity_per_trade']],
                'leverage' => ['state' => 'defined', 'value' => 5.0, 'unit' => 'leverage_multiple'],
            ],
            'setup' => [
                'setup_id' => $setupId,
                'setup_version' => '1.0.0',
                'side' => $side,
                'ast' => ['execution' => [
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
                    'stop' => $decision(['kind' => 'atr', 'timeframe' => '5m', 'atr_multiplier' => 1.5, 'pivot_id' => null, 'buffer_rate' => 0.001], 'stop_policy'),
                    'targets' => $decision([
                        ['id' => 'tp1', 'risk_multiple' => 1.5, 'liquidity_role' => 'taker'],
                        ['id' => 'tp2', 'risk_multiple' => 2.0, 'liquidity_role' => 'taker'],
                    ], 'target_policy'),
                    'minimum_net_r' => $decision(1.2, 'net_r_multiple'),
                    'invalidation' => $decision(['kind' => 'close_beyond_stop'], 'invalidation_policy'),
                    'time_stop' => $decision('PT30M', 'duration'),
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
                ]],
            ],
            'exchange' => ['id' => 'fake', 'fees' => ['maker_rate' => 0.0002, 'taker_rate' => 0.0005], 'limits' => ['min_notional' => 5.0, 'max_notional' => 1000.0]],
            'environment' => ['id' => 'test', 'max_notional' => 250.0, 'write_enabled' => false, 'kill_switch_enabled' => true, 'require_stop_loss' => true],
        ];
        $catalogHash = 'sha256:' . str_repeat('b', 64);
        $configHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $catalogHash);
        $snapshot = new EffectiveTradingConfigSnapshot(
            new EffectiveTradingConfigRequest('day_trading', '1.0.0', $setupId, '1.0.0', 'fake', 'test', $side),
            $payload,
            $configHash,
            $catalogHash,
            [],
            [],
        );

        return (new CanonicalExecutionPolicyCompiler())->compile($snapshot);
    }
}
