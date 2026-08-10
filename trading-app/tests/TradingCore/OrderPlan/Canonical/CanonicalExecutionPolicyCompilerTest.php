<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan\Canonical;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalExecutionPolicyCompiler::class)]
#[CoversClass(CanonicalExecutionPolicy::class)]
final class CanonicalExecutionPolicyCompilerTest extends TestCase
{
    public function testCompilesStrictExecutionPolicyFromAuthenticatedSnapshot(): void
    {
        $policy = (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot());

        self::assertSame('day_trading', $policy->riskPolicy->modeId);
        self::assertSame('day_trading.trend_continuation.long', $policy->riskPolicy->setupId);
        self::assertSame('vwap', $policy->entryZone->anchorSource);
        self::assertSame('5m', $policy->entryZone->anchorTimeframe);
        self::assertSame(0.5, $policy->entryZone->atrMultiplier);
        self::assertSame(0.001, $policy->entryZone->minimumHalfWidthRate);
        self::assertSame(0.01, $policy->entryZone->maximumHalfWidthRate);
        self::assertSame(0.2, $policy->entryZone->asymmetryRate);
        self::assertSame(180, $policy->entryZone->ttlSeconds);
        self::assertSame(60, $policy->entryZone->maximumInputAgeSeconds);
        self::assertTrue($policy->entryZone->quantizeOutward);
        self::assertSame('atr', $policy->stop->kind);
        self::assertSame('5m', $policy->stop->timeframe);
        self::assertSame(1.5, $policy->stop->atrMultiplier);
        self::assertNull($policy->stop->pivotId);
        self::assertCount(2, $policy->targets);
        self::assertSame('tp1', $policy->targets[0]->id);
        self::assertSame(1.5, $policy->targets[0]->riskMultiple);
        self::assertSame('taker', $policy->targets[0]->liquidityRole);
        self::assertSame(1.2, $policy->minimumNetR);
        self::assertSame(1800, $policy->holdingWindowSeconds);
        self::assertSame(28_800, $policy->costContract->fundingIntervalSeconds);
        self::assertSame('order_book', $policy->costContract->entrySpreadSource);
        self::assertSame($policy->riskPolicy->configHash, $policy->configHash);
    }

    public function testRejectsUnresolvedExecutionDecision(): void
    {
        $payload = $this->payload();
        $payload['setup']['ast']['execution']['entry_zone']['state'] = 'unresolved';
        $payload['setup']['ast']['execution']['entry_zone']['value'] = null;

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_execution_policy_unresolved');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload));
    }

    public function testRejectsUnknownEntryZoneKey(): void
    {
        $payload = $this->payload();
        $payload['setup']['ast']['execution']['entry_zone']['value']['fallback_anchor'] = 'reference_price';

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_policy_shape_invalid');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload));
    }

    public function testRejectsAmbiguousStopSource(): void
    {
        $payload = $this->payload();
        $payload['setup']['ast']['execution']['stop']['value']['kind'] = 'pivot_or_atr';

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_stop_policy_invalid');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload));
    }

    public function testRejectsLegacyEntryZoneAlias(): void
    {
        $payload = $this->payload();
        $entryZone = &$payload['setup']['ast']['execution']['entry_zone']['value'];
        $entryZone['from'] = $entryZone['anchor_source'];
        unset($entryZone['anchor_source']);

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_entry_zone_policy_shape_invalid');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload));
    }

    public function testRejectsWrongDecisionUnit(): void
    {
        $payload = $this->payload();
        $payload['setup']['ast']['execution']['minimum_net_r']['unit'] = 'gross_r_multiple';

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_execution_policy_unit_invalid');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload));
    }

    public function testRejectsTargetsThatAreNotStrictlyOrdered(): void
    {
        $payload = $this->payload();
        $payload['setup']['ast']['execution']['targets']['value'][1]['risk_multiple'] = 1.0;

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_target_policy_invalid');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload));
    }

    public function testRejectsCalendarBasedTimeStop(): void
    {
        $payload = $this->payload();
        $payload['setup']['ast']['execution']['time_stop']['value'] = 'P1D';

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_time_stop_invalid');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload));
    }

    public function testRejectsTimeStopThatCannotFitRuntimeSeconds(): void
    {
        $payload = $this->payload();
        $payload['setup']['ast']['execution']['time_stop']['value'] = 'PT999999999999999999999H';

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_time_stop_invalid');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload));
    }

    public function testRejectsConfigHashThatDoesNotAuthenticateExecutionPayload(): void
    {
        $snapshot = $this->snapshot();
        $payload = $this->payload();
        $payload['setup']['ast']['execution']['minimum_net_r']['value'] = 9.0;

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_execution_policy_hash_mismatch');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload, $snapshot->configHash));
    }

    public function testExecutionPolicyCannotBeConstructedOutsideCompiler(): void
    {
        $constructor = (new \ReflectionClass(CanonicalExecutionPolicy::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    /** @param array<string, mixed>|null $payload */
    private function snapshot(?array $payload = null, ?string $configHash = null): EffectiveTradingConfigSnapshot
    {
        $request = new EffectiveTradingConfigRequest(
            'day_trading',
            '1.0.0',
            'day_trading.trend_continuation.long',
            '1.0.0',
            'fake',
            'test',
            'long',
        );
        $payload ??= $this->payload();
        $conditionCatalogHash = 'sha256:' . str_repeat('b', 64);
        $configHash ??= CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $conditionCatalogHash);

        return new EffectiveTradingConfigSnapshot(
            request: $request,
            payload: $payload,
            configHash: $configHash,
            conditionCatalogHash: $conditionCatalogHash,
            layers: [],
            provenance: [],
        );
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $decision = static fn (mixed $value, string $unit): array => [
            'state' => 'defined',
            'value' => $value,
            'unit' => $unit,
            'source' => 'test-fixture',
            'justification' => 'canonical Lot B test fixture',
        ];

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
            'setup' => [
                'setup_id' => 'day_trading.trend_continuation.long',
                'setup_version' => '1.0.0',
                'side' => 'long',
                'ast' => [
                    'execution' => [
                        'side' => 'long',
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
                            'kind' => 'atr',
                            'timeframe' => '5m',
                            'atr_multiplier' => 1.5,
                            'pivot_id' => null,
                            'buffer_rate' => 0.001,
                        ], 'stop_policy'),
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
                    ],
                ],
            ],
            'exchange' => [
                'id' => 'fake',
                'fees' => ['maker_rate' => 0.0002, 'taker_rate' => 0.0005],
                'limits' => ['min_notional' => 5.0, 'max_notional' => 1000.0],
            ],
            'environment' => ['id' => 'test', 'max_notional' => 250.0, 'write_enabled' => false, 'kill_switch_enabled' => true, 'require_stop_loss' => true],
        ];
    }
}
