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
}
