<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan\Canonical;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalExecutionPolicyCompiler::class)]
#[CoversClass(CanonicalExecutionPolicy::class)]
final class CanonicalExecutionPolicyCompilerTest extends TestCase
{
    public function testCompilesPublishedDayTradingShadowOrderAndCostPolicy(): void
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'fake', 'test', 'long', ShadowExecutionCapability::Fake,
        ));
        $policy = (new CanonicalExecutionPolicyCompiler())->compile($snapshot);

        self::assertSame('15m', $policy->executionTimeframe);
        self::assertSame(['5m', '1m'], $policy->mandatoryConfirmations);
        self::assertSame('limit', $policy->orderPolicy->type);
        self::assertSame('maker', $policy->orderPolicy->liquidityRole);
        self::assertSame(90, $policy->orderPolicy->ttlSeconds);
        self::assertSame(120, $policy->orderPolicy->cancelAfterSeconds);
        self::assertFalse($policy->orderPolicy->marketFallback);
        self::assertSame(6.0, $policy->orderPolicy->maximumSpreadBps);
        self::assertSame(8.0, $policy->orderPolicy->maximumSlippageBps);
        self::assertSame('maker', $policy->costContract->entryLiquidityRole);
        self::assertSame('taker', $policy->costContract->stopLiquidityRole);
        self::assertSame(28_800, $policy->holdingWindowSeconds);
        self::assertSame('UTC', $policy->holdingHorizon['daily_boundary_timezone']);
    }

    public function testCompilesFundingCadenceFromTheAuthenticatedVenueSchedule(): void
    {
        $resolver = new EffectiveTradingConfigResolver();

        $hyperliquid = (new CanonicalExecutionPolicyCompiler())->compile($resolver->resolve(new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'hyperliquid', 'mainnet', 'long', ShadowExecutionCapability::Paper,
        )));
        $okx = (new CanonicalExecutionPolicyCompiler())->compile($resolver->resolve(new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'okx', 'demo', 'long', ShadowExecutionCapability::Paper,
        )));

        self::assertSame('venue_schedule', $hyperliquid->costContract->fundingSource);
        self::assertSame(3600, $hyperliquid->costContract->fundingIntervalSeconds);
        self::assertSame(28_800, $okx->costContract->fundingIntervalSeconds);
    }

    #[DataProvider('invalidVenueFundingSchedules')]
    public function testRejectsAnInvalidAuthenticatedVenueFundingSchedule(callable $mutate): void
    {
        $payload = $this->payload();
        $mutate($payload);

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_cost_contract_venue_schedule_invalid');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload));
    }

    /** @return iterable<string, array{callable(array<string, mixed>&): void}> */
    public static function invalidVenueFundingSchedules(): iterable
    {
        yield 'missing schedule' => [static function (array &$payload): void {
            unset($payload['exchange']['funding']);
        }];
        yield 'disabled schedule' => [static function (array &$payload): void {
            $payload['exchange']['funding']['enabled'] = false;
        }];
        yield 'calendar duration' => [static function (array &$payload): void {
            $payload['exchange']['funding']['interval'] = 'P1D';
        }];
        yield 'unknown schedule key' => [static function (array &$payload): void {
            $payload['exchange']['funding']['fallback_interval'] = 'PT8H';
        }];
    }

    #[DataProvider('scalpingIdentities')]
    public function testCompilesOnlyTheExactPublishedScalpingShadowEnvelope(string $setupId, string $side): void
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', $setupId, '1.1.0',
            'fake', 'test', $side, ShadowExecutionCapability::Fake,
        ));

        $policy = (new CanonicalExecutionPolicyCompiler())->compile($snapshot);

        self::assertSame('5m', $policy->executionTimeframe);
        self::assertSame(['1m'], $policy->mandatoryConfirmations);
        self::assertNotNull($policy->orderPolicy);
        self::assertSame('limit', $policy->orderPolicy->type);
        self::assertSame('maker', $policy->orderPolicy->liquidityRole);
        self::assertSame(45, $policy->orderPolicy->ttlSeconds);
        self::assertSame(75, $policy->orderPolicy->cancelAfterSeconds);
        self::assertFalse($policy->orderPolicy->marketFallback);
        self::assertSame(7200, $policy->holdingWindowSeconds);
        self::assertSame('UTC', $policy->holdingHorizon['daily_boundary_timezone']);
        self::assertSame(0.02, $policy->riskPolicy->riskRate);
        self::assertSame(3.0, $policy->riskPolicy->modeLeverageCap);
        self::assertSame(25.0, $policy->riskPolicy->exchangeMaxNotional);
        self::assertSame(1.3, $policy->minimumNetR);
        self::assertCount(1, $policy->targets);
        self::assertSame(1.8, $policy->targets[0]->riskMultiple);
    }

    /** @return iterable<string, array{string, string}> */
    public static function scalpingIdentities(): iterable
    {
        yield 'trend continuation long' => ['scalping.trend_continuation.long', 'long'];
        yield 'pullback long' => ['scalping.pullback.long', 'long'];
        yield 'trend momentum short' => ['scalping.trend_momentum.short', 'short'];
    }

    #[DataProvider('microScalpingIdentities')]
    public function testCompilesExactMicroScalpingShadowEnvelope(string $setupId, string $side): void
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'micro_scalping', '1.1.0', $setupId, '1.1.0',
            'okx', 'demo', $side, ShadowExecutionCapability::Paper,
        ));

        $policy = (new CanonicalExecutionPolicyCompiler())->compile($snapshot);

        self::assertSame('1m', $policy->executionTimeframe);
        self::assertSame(['1m'], $policy->mandatoryConfirmations);
        self::assertSame(30, $policy->orderPolicy->ttlSeconds);
        self::assertSame(60, $policy->orderPolicy->cancelAfterSeconds);
        self::assertFalse($policy->orderPolicy->marketFallback);
        self::assertSame(1800, $policy->holdingWindowSeconds);
        self::assertSame(0.004, $policy->riskPolicy->riskRate);
        self::assertSame(2.0, $policy->riskPolicy->modeLeverageCap);
        self::assertSame(10.0, $policy->riskPolicy->exchangeMaxNotional);
        self::assertSame(1.3, $policy->minimumNetR);
        self::assertSame(1.8, $policy->targets[0]->riskMultiple);
    }

    /** @return iterable<string, array{string, string}> */
    public static function microScalpingIdentities(): iterable
    {
        yield 'momentum OFI long' => ['micro_scalping.momentum_ofi.long', 'long'];
        yield 'momentum OFI short' => ['micro_scalping.momentum_ofi.short', 'short'];
    }

    public function testLegacyIdentityCannotOptIntoModernShadowExecutionDecisions(): void
    {
        $payload = $this->payload();
        $execution = &$payload['setup']['ast']['execution'];
        $execution['execution_timeframe'] = self::decision('15m', 'timeframe');
        $execution['mandatory_confirmations'] = self::decision(['5m', '1m'], 'timeframes');
        $execution['order_policy'] = self::decision([
            'type' => 'limit',
            'liquidity_role' => 'maker',
            'ttl_seconds' => 90,
            'cancel_after_seconds' => 120,
            'market_fallback' => false,
            'maximum_spread_bps' => 6.0,
            'maximum_slippage_bps' => 8.0,
        ], 'order_policy');
        unset($execution);

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_execution_policy_shape_invalid');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload));
    }

    public function testRejectsCrossModeShadowOrderAndHoldingPoliciesAfterReauthentication(): void
    {
        $resolver = new EffectiveTradingConfigResolver();
        $scalping = $resolver->resolve(new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', 'scalping.trend_continuation.long', '1.1.0',
            'fake', 'test', 'long', ShadowExecutionCapability::Fake,
        ));
        $dayTrading = $resolver->resolve(new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'fake', 'test', 'long', ShadowExecutionCapability::Fake,
        ));

        foreach ([
            [$scalping, static function (array &$payload): void {
                $payload['setup']['ast']['execution']['order_policy']['value']['ttl_seconds'] = 90;
                $payload['setup']['ast']['execution']['order_policy']['value']['cancel_after_seconds'] = 120;
            }],
            [$scalping, static function (array &$payload): void {
                $payload['setup']['ast']['execution']['time_stop']['value'] = 'PT8H';
                $payload['mode']['horizon']['value']['maximum_duration'] = 'PT8H';
            }],
            [$dayTrading, static function (array &$payload): void {
                $payload['setup']['ast']['execution']['order_policy']['value']['ttl_seconds'] = 45;
                $payload['setup']['ast']['execution']['order_policy']['value']['cancel_after_seconds'] = 75;
            }],
            [$dayTrading, static function (array &$payload): void {
                $payload['setup']['ast']['execution']['time_stop']['value'] = 'PT2H';
                $payload['mode']['horizon']['value']['maximum_duration'] = 'PT2H';
            }],
        ] as [$snapshot, $mutate]) {
            $payload = $snapshot->payload();
            $mutate($payload);
            $payload = CanonicalExecutionPolicyFixture::rehashSetup($payload);
            $forged = new EffectiveTradingConfigSnapshot(
                $snapshot->request,
                $payload,
                CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, (string) $snapshot->conditionCatalogHash),
                $snapshot->conditionCatalogHash,
                $snapshot->orderedLayers(),
                $snapshot->provenance(),
            );

            try {
                (new CanonicalExecutionPolicyCompiler())->compile($forged);
                self::fail('Cross-mode Shadow timing policy was accepted.');
            } catch (CanonicalOrderPlanException $exception) {
                self::assertSame('canonical_shadow_identity_policy_mismatch', $exception->reasonCode);
            }
        }
    }

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
        self::assertSame(['BTCUSDT'], $policy->allowedSymbols);
        self::assertSame(['perpetual'], $policy->allowedMarkets);
        self::assertSame($policy->riskPolicy->configHash, $policy->configHash);
    }

    public function testRejectsEnvironmentWithoutCanonicalAllowlists(): void
    {
        $payload = $this->payload();
        unset($payload['environment']['allowed_symbols']);

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_execution_policy_environment_invalid');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload));
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

    public function testRejectsUnknownCanonicalRootKey(): void
    {
        $payload = $this->payload();
        $payload['legacy_defaults'] = [];

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_execution_policy_root_schema_invalid');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload));
    }

    public function testRejectsIncompleteCompiledSetupEnvelope(): void
    {
        $payload = $this->payload();
        unset($payload['setup']['publishable']);

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_execution_policy_setup_schema_invalid');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload));
    }

    public function testRejectsIncompleteCanonicalAst(): void
    {
        $payload = $this->payload();
        unset($payload['setup']['ast']['filters']);

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_execution_policy_ast_schema_invalid');
        (new CanonicalExecutionPolicyCompiler())->compile($this->snapshot($payload));
    }

    public function testRejectsNestedConditionCatalogConflict(): void
    {
        $payload = $this->payload();
        $payload['setup']['data_condition_contract']['condition_catalog_hash']['state'] = 'unresolved';
        $payload['setup']['data_condition_contract']['condition_catalog_hash']['value'] = null;

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_execution_policy_catalog_invalid');
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
        $payload = CanonicalExecutionPolicyFixture::rehashSetup($payload ?? $this->payload());
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
        return CanonicalExecutionPolicyFixture::payload();
    }

    /** @return array{state:string,value:mixed,unit:string,source:string,justification:string} */
    private static function decision(mixed $value, string $unit): array
    {
        return [
            'state' => 'defined',
            'value' => $value,
            'unit' => $unit,
            'source' => 'test fixture',
            'justification' => 'Proves that legacy identities cannot enable modern Shadow fields.',
        ];
    }
}
