<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Lineage\LineageContext;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;

final class CanonicalSnapshotFixture
{
    /** @return array<string,mixed> */
    public static function config(): array
    {
        $defined = static fn (mixed $value, string $unit): array => ['state' => 'defined', 'value' => $value, 'unit' => $unit];
        return [
            'schema_version' => 'effective-trading-config.v2',
            'units' => ['percent' => 'percentage_points', 'duration' => 'iso8601', 'price' => 'quote_price', 'notional' => 'quote_notional'],
            'safety' => ['mainnet_write_enabled' => false, 'demo_testnet_write_enabled' => false, 'require_stop_loss' => true, 'kill_switch_enabled' => true],
            'mode' => [
                'mode_id' => 'scalping', 'mode_version' => '1.0.0',
                'timeframes' => ['regime' => ['15m'], 'context' => ['5m'], 'trigger' => ['1m'], 'execution' => ['1m', '5m']],
                'risk' => [
                    'trade_budget' => $defined(['amount' => 50.0, 'quote_currency' => 'USDT'], 'quote_notional'),
                    'daily_loss_cap' => $defined(['percent_equity' => 2.0, 'absolute_quote' => 20.0, 'quote_currency' => 'USDT'], 'compound_percent_equity_and_quote_per_day'),
                    'max_concurrent_positions' => $defined(3, 'positions'),
                    'mode_exposure_cap' => $defined(10.0, 'percent_equity_notional'),
                ],
                'leverage' => $defined(3.0, 'leverage_multiple'),
                'order_policy' => $defined(['margin_mode' => 'isolated', 'preferred_type' => 'limit'], 'policy'),
            ],
            'setup' => [
                'setup_id' => 'scalping.pullback.long', 'setup_version' => '1.0.0', 'side' => 'long', 'executable' => true, 'publishable' => true,
                'ast' => ['execution' => [
                    'entry_zone' => $defined(['from' => 'vwap', 'offset_atr_tf' => '1m', 'k_atr' => 0.3, 'w_min' => 0.0005, 'w_max' => 0.01, 'ttl_sec' => 180, 'quantize_to_exchange_step' => true], 'price_zone_policy'),
                    'stop' => $defined(['from' => 'atr', 'atr_k' => 1.5], 'stop_policy'),
                    'targets' => $defined(['r_multiple' => 2.0], 'target_policy'),
                    'minimum_net_r' => $defined(1.3, 'net_r_multiple'),
                    'invalidation' => $defined(['policy' => 'setup'], 'invalidation_policy'),
                    'time_stop' => $defined('PT15M', 'duration'),
                    'cost_contract' => $defined(['max_spread_rate' => 0.001], 'net_cost_model'), 'side' => 'long',
                ]],
            ],
            'exchange' => ['id' => 'fake', 'capabilities' => ['orders' => true, 'order_types' => ['limit'], 'stop_loss' => true, 'take_profit' => true, 'reduce_only' => true], 'fees' => ['maker_rate' => 0.0002, 'taker_rate' => 0.0006], 'funding' => ['enabled' => false, 'interval' => 'PT8H'], 'precision' => ['price_decimals' => 2, 'quantity_decimals' => 3], 'limits' => ['max_orders' => 10, 'min_notional' => 1.0, 'max_notional' => 100.0]],
            'environment' => ['id' => 'test', 'allowed_symbols' => ['BTCUSDT'], 'allowed_markets' => ['perpetual'], 'max_notional' => 10.0, 'dry_run' => true, 'write_enabled' => false, 'kill_switch_enabled' => true, 'require_stop_loss' => true],
        ];
    }

    /** @param array<string,mixed> $config */
    public static function lineage(array $config, ?string $catalogDigest = null): LineageContext
    {
        $catalogDigest ??= (new ConditionCatalogLoader())->loadFile(
            dirname(__DIR__, 3) . '/config/trading/condition_catalog/1.0.0.yaml',
        )->stableHash();
        $catalog = 'sha256:' . $catalogDigest;
        $hash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($config, $catalog);
        $snapshot = CanonicalSnapshotMetadataFixture::enrich([
            'request' => ['mode_id' => 'scalping', 'mode_version' => '1.0.0', 'setup_id' => 'scalping.pullback.long', 'setup_version' => '1.0.0', 'exchange' => 'fake', 'environment' => 'test', 'side' => 'long'],
            'config' => $config, 'config_hash' => $hash, 'condition_catalog_hash' => $catalog, 'executable' => true, 'blockers' => [],
        ]);
        return LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator', 'orchestration_run_id' => 'run-fixture', 'orchestration_set_id' => 'set-fixture',
            'mode_id' => 'scalping', 'mode_version' => '1.0.0', 'setup_id' => 'scalping.pullback.long', 'setup_version' => '1.0.0',
            'config_hash' => $hash, 'condition_catalog_hash' => $catalog, 'side' => 'LONG', 'exchange' => 'fake', 'environment' => 'test',
            'market_type' => 'perpetual', 'symbol' => 'BTCUSDT', 'dry_run' => true,
            'effective_config_reference' => 'effective-config:fixture', 'effective_config_snapshot' => $snapshot,
        ]);
    }
}
