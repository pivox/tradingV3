<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Risk\Canonical;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalRiskPolicy;
use App\TradingCore\Risk\Canonical\CanonicalRiskPolicyCompiler;

final class CanonicalRiskTestFactory
{
    public static function policy(
        string $side,
        float $riskRate = 0.01,
        float $modeLeverageCap = 5.0,
        float $makerFeeRate = 0.0,
        float $takerFeeRate = 0.0,
        float $exchangeMinNotional = 1.0,
        float $exchangeMaxNotional = 1000.0,
        float $environmentMaxNotional = 500.0,
    ): CanonicalRiskPolicy {
        $setupId = 'day_trading.trend_continuation.' . $side;
        $request = new EffectiveTradingConfigRequest(
            'day_trading',
            '1.0.0',
            $setupId,
            '1.0.0',
            'fake',
            'test',
            $side,
        );
        $payload = [
            'schema_version' => 'effective-trading-config.v2',
            'units' => [
                'percent' => 'percentage_points',
                'duration' => 'iso8601',
                'price' => 'quote_price',
                'notional' => 'quote_notional',
            ],
            'safety' => [
                'mainnet_write_enabled' => false,
                'demo_testnet_write_enabled' => false,
                'require_stop_loss' => true,
                'kill_switch_enabled' => true,
            ],
            'mode' => [
                'mode_id' => 'day_trading',
                'mode_version' => '1.0.0',
                'risk' => [
                    'trade_budget' => [
                        'state' => 'defined',
                        'value' => $riskRate * 100.0,
                        'unit' => 'percent_equity_per_trade',
                    ],
                ],
                'leverage' => [
                    'state' => 'defined',
                    'value' => $modeLeverageCap,
                    'unit' => 'leverage_multiple',
                ],
            ],
            'setup' => [
                'setup_id' => $setupId,
                'setup_version' => '1.0.0',
                'side' => $side,
            ],
            'exchange' => [
                'id' => 'fake',
                'fees' => ['maker_rate' => $makerFeeRate, 'taker_rate' => $takerFeeRate],
                'limits' => ['min_notional' => $exchangeMinNotional, 'max_notional' => $exchangeMaxNotional],
            ],
            'environment' => [
                'id' => 'test',
                'max_notional' => $environmentMaxNotional,
                'write_enabled' => false,
                'kill_switch_enabled' => true,
                'require_stop_loss' => true,
            ],
        ];
        $conditionCatalogHash = 'sha256:' . str_repeat('b', 64);
        try {
            $configHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $conditionCatalogHash);
        } catch (\InvalidArgumentException) {
            $configHash = 'sha256:' . str_repeat('a', 64);
        }
        $snapshot = new EffectiveTradingConfigSnapshot(
            request: $request,
            payload: $payload,
            configHash: $configHash,
            conditionCatalogHash: $conditionCatalogHash,
            layers: [],
            provenance: [],
        );

        return (new CanonicalRiskPolicyCompiler())->compile($snapshot);
    }
}
