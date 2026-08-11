<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Policy;

use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Condition\ConditionResult;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntimeResult;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use App\Trading\Lineage\LineageContext;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalSetupRuleRuntime::class)]
#[CoversClass(CanonicalSetupRuleRuntimeResult::class)]
final class CanonicalSetupRuleRuntimeTest extends TestCase
{
    public function testScalpingShadowUsesContractDefinedExecutionAndConfirmationTrace(): void
    {
        foreach ([
            ['scalping.trend_continuation.long', 'long'],
            ['scalping.pullback.long', 'long'],
            ['scalping.trend_momentum.short', 'short'],
        ] as [$setupId, $side]) {
            $result = (new CanonicalSetupRuleRuntime($this->passingConditions()))->evaluate(
                $this->scalpingLineage($setupId, $side),
                $this->scalpingInputs(),
                new \DateTimeImmutable('2026-08-10T12:00:00Z'),
            );

            self::assertTrue($result->passed, $setupId . ': ' . $result->reasonCode);
            self::assertSame('5m', $result->trace['execution_timeframe']);
            self::assertSame(['1m'], $result->trace['mandatory_confirmations']);
        }
    }

    public function testScalpingShadowRejectsMissingAndStaleMandatoryConfirmationGenerically(): void
    {
        $inputs = $this->scalpingInputs();
        unset($inputs['1m']);
        $missing = (new CanonicalSetupRuleRuntime($this->passingConditions()))->evaluate(
            $this->scalpingLineage('scalping.trend_continuation.long', 'long'),
            $inputs,
            new \DateTimeImmutable('2026-08-10T12:00:00Z'),
        );
        $staleInputs = $this->scalpingInputs();
        $staleInputs['1m']['kline_time'] = '2026-08-10T11:55:00Z';
        $stale = (new CanonicalSetupRuleRuntime($this->passingConditions()))->evaluate(
            $this->scalpingLineage('scalping.trend_continuation.long', 'long'),
            $staleInputs,
            new \DateTimeImmutable('2026-08-10T12:00:00Z'),
        );

        self::assertSame('critical_timeframe_missing', $missing->reasonCode);
        self::assertSame('1m', $missing->trace['rejection']['timeframe']);
        self::assertSame('5m', $missing->trace['execution_timeframe']);
        self::assertSame(['1m'], $missing->trace['mandatory_confirmations']);
        self::assertSame('critical_timeframe_stale', $stale->reasonCode);
        self::assertSame('5m', $stale->trace['execution_timeframe']);
        self::assertSame(['1m'], $stale->trace['mandatory_confirmations']);
    }

    public function testDayTradingShadowRejectsMissingMandatoryTimeframeWithoutFallback(): void
    {
        $result = (new CanonicalSetupRuleRuntime([]))->evaluate(
            $this->dayTradingLineage(),
            [
                '4h' => ['kline_time' => '2026-08-10T08:00:00Z'],
                '1h' => ['kline_time' => '2026-08-10T09:00:00Z'],
                '15m' => ['kline_time' => '2026-08-10T09:45:00Z'],
                '5m' => ['kline_time' => '2026-08-10T09:55:00Z'],
            ],
            new \DateTimeImmutable('2026-08-10T10:00:00Z'),
        );

        self::assertFalse($result->passed);
        self::assertSame('critical_timeframe_missing', $result->reasonCode);
        self::assertSame('1m', $result->trace['rejection']['timeframe']);
        self::assertSame('day_trading', $result->trace['mode_id']);
        self::assertSame('1.1.0', $result->trace['mode_version']);
        self::assertSame('15m', $result->trace['execution_timeframe']);
        self::assertStringNotContainsString('fallback', json_encode($result->trace, JSON_THROW_ON_ERROR));
    }

    public function testDayTradingShadowNormalizesStaleMandatoryTimeframeRejection(): void
    {
        $result = (new CanonicalSetupRuleRuntime([]))->evaluate(
            $this->dayTradingLineage(),
            [
                '4h' => ['kline_time' => '2026-08-10T08:00:00Z'],
                '1h' => ['kline_time' => '2026-08-10T09:00:00Z'],
                '15m' => ['kline_time' => '2026-08-10T09:45:00Z'],
                '5m' => ['kline_time' => '2026-08-10T09:00:00Z'],
                '1m' => ['kline_time' => '2026-08-10T09:59:00Z'],
            ],
            new \DateTimeImmutable('2026-08-10T10:00:00Z'),
        );

        self::assertFalse($result->passed);
        self::assertSame('critical_timeframe_stale', $result->reasonCode);
        self::assertSame('15m', $result->trace['execution_timeframe']);
        self::assertSame('sha256:', substr((string) $result->trace['config_hash'], 0, 7));
    }

    public function testDayTradingShadowPassesOnlyTheFixedFreshLongChain(): void
    {
        $result = (new CanonicalSetupRuleRuntime($this->passingConditions()))->evaluate(
            $this->dayTradingLineage(),
            [
                '4h' => ['kline_time' => '2026-08-10T08:00:00Z', 'series_order' => 'oldest_to_newest'],
                '1h' => ['kline_time' => '2026-08-10T09:00:00Z', 'adx' => 25.0, 'series_order' => 'oldest_to_newest'],
                '15m' => ['kline_time' => '2026-08-10T09:45:00Z', 'series_order' => 'oldest_to_newest'],
                '5m' => ['kline_time' => '2026-08-10T09:55:00Z', 'series_order' => 'oldest_to_newest'],
                '1m' => ['kline_time' => '2026-08-10T09:59:00Z', 'series_order' => 'oldest_to_newest'],
            ],
            new \DateTimeImmutable('2026-08-10T10:00:00Z'),
        );

        self::assertTrue($result->passed);
        self::assertSame('setup_rules_passed', $result->reasonCode);
        self::assertSame('15m', $result->trace['execution_timeframe']);
        self::assertSame(['5m', '1m'], $result->trace['mandatory_confirmations']);
    }

    public function testRealCanonicalSetupUsesStrictPlanAndNeverReportsLegacyFallback(): void
    {
        $now = new \DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $result = (new CanonicalSetupRuleRuntime([]))->evaluate(
            CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config()),
            [
                '15m' => ['kline_time' => '2026-08-10T09:45:00+00:00'],
                '5m' => ['kline_time' => '2026-08-10T09:55:00+00:00'],
                '1m' => ['kline_time' => '2026-08-10T09:59:00+00:00'],
            ],
            $now,
        );

        self::assertFalse($result->passed);
        self::assertSame('compiled_plan_blocked', $result->reasonCode);
        self::assertSame('canonical-setup-rule-runtime.v1', $result->trace['schema_version']);
        self::assertSame('scalping.pullback.long', $result->trace['setup_id']);
        self::assertSame('1.0.0', $result->trace['setup_version']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->trace['setup_hash']);
        self::assertArrayHasKey('regime', $result->trace['sections']);
        self::assertStringNotContainsString('fallback', json_encode($result->trace, JSON_THROW_ON_ERROR));
    }

    public function testLegacyAndCatalogMismatchRejectBeforeEvaluation(): void
    {
        $runtime = new CanonicalSetupRuleRuntime([]);
        $now = new \DateTimeImmutable('2026-08-10T10:00:00+00:00');

        self::assertSame('canonical_identity_required', $runtime->evaluate(
            \App\Trading\Lineage\LineageContext::legacy(),
            [],
            $now,
        )->reasonCode);
        self::assertSame('canonical_condition_catalog_mismatch', $runtime->evaluate(
            CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config(), str_repeat('b', 64)),
            [],
            $now,
        )->reasonCode);
    }

    public function testCompiledPlanCacheIsBoundToExactCatalogSetupAndConfigHashes(): void
    {
        $runtime = new CanonicalSetupRuleRuntime([]);
        $identity = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config());
        $now = new \DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $inputs = ['15m' => ['kline_time' => '2026-08-10T09:45:00+00:00']];

        $first = $runtime->evaluate($identity, $inputs, $now);
        $second = $runtime->evaluate($identity, $inputs, $now);

        self::assertFalse($first->trace['plan_cache_hit']);
        self::assertTrue($second->trace['plan_cache_hit']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->trace['plan_cache_key']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->trace['setup_hash']);
        self::assertSame($first->trace['plan_cache_key'], $second->trace['plan_cache_key']);
    }

    private function dayTradingLineage(): LineageContext
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'fake', 'test', 'long', ShadowExecutionCapability::Fake,
        ));

        return LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-day-trading-shadow',
            'orchestration_set_id' => 'set-day-trading-shadow',
            'mode_id' => 'day_trading',
            'mode_version' => '1.1.0',
            'setup_id' => 'day_trading.trend_continuation.long',
            'setup_version' => '1.1.0',
            'config_hash' => $snapshot->configHash,
            'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'side' => 'LONG',
            'exchange' => 'fake',
            'environment' => 'test',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'dry_run' => true,
            'effective_config_reference' => 'effective-config:day-trading-shadow',
            'effective_config_snapshot' => $snapshot->toArray(),
        ]);
    }

    private function scalpingLineage(string $setupId, string $side): LineageContext
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', $setupId, '1.1.0',
            'fake', 'test', $side, ShadowExecutionCapability::Fake,
        ));

        return LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-scalping-shadow',
            'orchestration_set_id' => 'set-scalping-shadow',
            'mode_id' => 'scalping',
            'mode_version' => '1.1.0',
            'setup_id' => $setupId,
            'setup_version' => '1.1.0',
            'config_hash' => $snapshot->configHash,
            'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'side' => strtoupper($side),
            'exchange' => 'fake',
            'environment' => 'test',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'decision_key' => 'decision-scalping-shadow',
            'dry_run' => true,
            'effective_config_reference' => 'effective-config:scalping-shadow',
            'effective_config_snapshot' => $snapshot->toArray(),
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function scalpingInputs(): array
    {
        return [
            '1h' => ['kline_time' => '2026-08-10T11:00:00Z', 'series_order' => 'oldest_to_newest'],
            '15m' => ['kline_time' => '2026-08-10T11:45:00Z', 'series_order' => 'oldest_to_newest', 'pullback_age_bars' => 1],
            '5m' => ['kline_time' => '2026-08-10T11:55:00Z', 'series_order' => 'oldest_to_newest'],
            '1m' => ['kline_time' => '2026-08-10T11:59:00Z', 'series_order' => 'oldest_to_newest'],
        ];
    }

    /** @return list<ConditionInterface> */
    private function passingConditions(): array
    {
        $ids = (new \App\TradingCore\Rules\Catalog\ConditionCatalogLoader())->loadFile(
            dirname(__DIR__, 3) . '/config/trading/condition_catalog/1.0.0.yaml',
        )->conditionIds();

        return array_map(static fn (string $id): ConditionInterface => new class($id) implements ConditionInterface {
            public function __construct(private readonly string $id)
            {
            }

            public function getName(): string
            {
                return $this->id;
            }

            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult
            {
                return new ConditionResult($this->id, true);
            }
        }, $ids);
    }
}
