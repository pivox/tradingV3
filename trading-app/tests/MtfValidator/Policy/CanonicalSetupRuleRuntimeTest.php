<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Policy;

use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Condition\ConditionResult;
use App\Indicator\Condition\OrderFlowImbalanceGteCondition;
use App\Indicator\Condition\OrderFlowImbalanceLteCondition;
use App\Indicator\Condition\SpreadBpsLteCondition;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntimeResult;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use App\Tests\Trading\Lineage\CanonicalSnapshotMetadataFixture;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Lineage\LineageContext;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicBook;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicTrade;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\Microstructure\CanonicalMicrostructureEngine;
use App\TradingCore\Microstructure\CanonicalMicrostructurePolicy;
use App\TradingCore\Microstructure\CanonicalMicrostructureRuntimeInputResolver;
use App\TradingCore\Microstructure\CanonicalMicrostructureSnapshot;
use App\TradingCore\Microstructure\CanonicalMicrostructureSnapshotProviderInterface;
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
            self::assertSame('1.1.0', $result->trace['catalog_version']);
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
        self::assertSame('timeframe_mapping_missing', $missing->trace['rejection']['cause']);
        self::assertSame('5m', $missing->trace['execution_timeframe']);
        self::assertSame(['1m'], $missing->trace['mandatory_confirmations']);
        self::assertSame('critical_timeframe_stale', $stale->reasonCode);
        self::assertSame('outside_freshness_window', $stale->trace['rejection']['cause']);
        self::assertSame('5m', $stale->trace['execution_timeframe']);
        self::assertSame(['1m'], $stale->trace['mandatory_confirmations']);
    }

    public function testScalpingShadowRejectsMissingAndCrossMarketSnapshotIdentityBeforeConditionEvaluation(): void
    {
        $evaluations = 0;
        $condition = new class($evaluations) implements ConditionInterface {
            public function __construct(private int &$evaluations)
            {
            }

            public function getName(): string
            {
                return 'ema20_gt_ema50';
            }

            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult
            {
                ++$this->evaluations;

                return new ConditionResult($this->getName(), true);
            }
        };
        $runtime = new CanonicalSetupRuleRuntime([$condition]);
        $lineage = $this->scalpingLineage('scalping.trend_continuation.long', 'long');

        foreach ([
            'missing' => null,
            'timeframe' => ['timeframe' => '15m', 'symbol' => 'BTCUSDT', 'exchange' => 'fake', 'environment' => 'test', 'market_type' => 'perpetual'],
            'symbol' => ['timeframe' => '1m', 'symbol' => 'ETHUSDT', 'exchange' => 'fake', 'environment' => 'test', 'market_type' => 'perpetual'],
            'exchange' => ['timeframe' => '1m', 'symbol' => 'BTCUSDT', 'exchange' => 'okx', 'environment' => 'test', 'market_type' => 'perpetual'],
            'environment' => ['timeframe' => '1m', 'symbol' => 'BTCUSDT', 'exchange' => 'fake', 'environment' => 'local', 'market_type' => 'perpetual'],
            'market type' => ['timeframe' => '1m', 'symbol' => 'BTCUSDT', 'exchange' => 'fake', 'environment' => 'test', 'market_type' => 'spot'],
        ] as $label => $snapshotIdentity) {
            $inputs = $this->scalpingInputs();
            if ($snapshotIdentity === null) {
                unset($inputs['1m']['snapshot_identity']);
            } else {
                $inputs['1m']['snapshot_identity'] = $snapshotIdentity;
            }

            $result = $runtime->evaluate($lineage, $inputs, new \DateTimeImmutable('2026-08-10T12:00:00Z'));

            self::assertFalse($result->passed, $label);
            self::assertSame('indicator_snapshot_identity_mismatch', $result->reasonCode, $label);
            self::assertSame('1m', $result->trace['rejection']['timeframe'], $label);
            self::assertSame($label === 'missing' ? 'identity_missing_or_invalid' : 'identity_mismatch', $result->trace['rejection']['cause'], $label);
            self::assertSame(0, $evaluations, $label);
        }
    }

    public function testScalpingShadowTreatsEveryUnparseableRequiredKlineTimeAsMissing(): void
    {
        foreach ([
            'absent' => [],
            'null' => ['kline_time' => null],
            'malformed' => ['kline_time' => 'not-an-instant'],
            'invalid type' => ['kline_time' => []],
        ] as $label => $oneMinuteInput) {
            $inputs = $this->scalpingInputs();
            $inputs['1m'] = $oneMinuteInput;

            $result = (new CanonicalSetupRuleRuntime($this->passingConditions()))->evaluate(
                $this->scalpingLineage('scalping.trend_continuation.long', 'long'),
                $inputs,
                new \DateTimeImmutable('2026-08-10T12:00:00Z'),
            );

            self::assertSame('critical_timeframe_missing', $result->reasonCode, $label);
            self::assertSame('1m', $result->trace['rejection']['timeframe'], $label);
            self::assertSame('kline_time_missing_or_invalid', $result->trace['rejection']['cause'], $label);
        }
    }

    public function testDayTradingShadowRejectsMissingMandatoryTimeframeWithoutFallback(): void
    {
        $result = (new CanonicalSetupRuleRuntime([]))->evaluate(
            $this->dayTradingLineage(),
            [
                '4h' => self::indicatorInput('4h', '2026-08-10T08:00:00Z'),
                '1h' => self::indicatorInput('1h', '2026-08-10T09:00:00Z'),
                '15m' => self::indicatorInput('15m', '2026-08-10T09:45:00Z'),
                '5m' => self::indicatorInput('5m', '2026-08-10T09:55:00Z'),
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
                '4h' => self::indicatorInput('4h', '2026-08-10T08:00:00Z'),
                '1h' => self::indicatorInput('1h', '2026-08-10T09:00:00Z'),
                '15m' => self::indicatorInput('15m', '2026-08-10T09:45:00Z'),
                '5m' => self::indicatorInput('5m', '2026-08-10T09:00:00Z'),
                '1m' => self::indicatorInput('1m', '2026-08-10T09:59:00Z'),
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
                '4h' => self::indicatorInput('4h', '2026-08-10T08:00:00Z'),
                '1h' => self::indicatorInput('1h', '2026-08-10T09:00:00Z', ['adx' => 25.0]),
                '15m' => self::indicatorInput('15m', '2026-08-10T09:45:00Z'),
                '5m' => self::indicatorInput('5m', '2026-08-10T09:55:00Z'),
                '1m' => self::indicatorInput('1m', '2026-08-10T09:59:00Z'),
            ],
            new \DateTimeImmutable('2026-08-10T10:00:00Z'),
        );

        self::assertTrue($result->passed);
        self::assertSame('setup_rules_passed', $result->reasonCode);
        self::assertSame('1.0.0', $result->trace['catalog_version']);
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

    public function testBlockedMicroContractCarriesAuthenticatedRuntimeInputWithoutChangingPublishedBlockers(): void
    {
        $snapshot = $this->microstructureSnapshot();
        $provider = new class($snapshot) implements CanonicalMicrostructureSnapshotProviderInterface {
            public function __construct(private readonly CanonicalMicrostructureSnapshot $snapshot) {}
            public function snapshotFor(LineageContext $identity, \DateTimeImmutable $evaluatedAt): ?CanonicalMicrostructureSnapshot
            {
                return $this->snapshot;
            }
        };
        $runtime = new CanonicalSetupRuleRuntime(
            $this->passingConditions(),
            microstructureInputs: new CanonicalMicrostructureRuntimeInputResolver($provider),
        );

        $result = $runtime->evaluate(
            $this->microLineage(),
            [
                '5m' => self::indicatorInput('5m', '2026-08-14T11:55:00Z'),
                '1m' => self::indicatorInput('1m', '2026-08-14T11:59:00Z'),
            ],
            new \DateTimeImmutable('2026-08-14T12:01:00.000000Z'),
        );

        self::assertSame('compiled_plan_blocked', $result->reasonCode);
        self::assertSame('ready', $result->trace['microstructure_input']['status']);
        self::assertSame($snapshot->inputHash, $result->trace['microstructure_input']['input_hash']);
        self::assertSame('okx', $result->trace['microstructure_input']['expected_market_identity']['market_data_venue']);
        self::assertContains('blocked_condition:spread_bps_lte', $result->trace['blockers']);
    }

    public function testExecutableMicroShadowPassesOnlyWithFreshAuthenticatedMicrostructure(): void
    {
        $snapshot = $this->microstructureSnapshot();
        $provider = new class($snapshot) implements CanonicalMicrostructureSnapshotProviderInterface {
            public function __construct(private readonly CanonicalMicrostructureSnapshot $snapshot) {}
            public function snapshotFor(LineageContext $identity, \DateTimeImmutable $evaluatedAt): ?CanonicalMicrostructureSnapshot
            {
                return $this->snapshot;
            }
        };
        $inputs = [
            '5m' => self::indicatorInput('5m', '2026-08-14T11:55:00Z'),
            '1m' => self::indicatorInput('1m', '2026-08-14T11:59:00Z'),
        ];
        foreach ($inputs as $timeframe => &$input) {
            $input['snapshot_identity'] = [
                'timeframe' => $timeframe,
                'symbol' => 'BTCUSDT',
                'exchange' => 'okx',
                'environment' => 'mainnet',
                'market_type' => 'perpetual',
            ];
        }
        unset($input);

        $result = (new CanonicalSetupRuleRuntime(
            $this->passingConditions('1.2.0'),
            microstructureInputs: new CanonicalMicrostructureRuntimeInputResolver($provider),
        ))->evaluate(
            $this->executableMicroLineage(),
            $inputs,
            new \DateTimeImmutable('2026-08-14T12:01:00.000000Z'),
        );

        self::assertTrue($result->passed, $result->reasonCode);
        self::assertSame('setup_rules_passed', $result->reasonCode);
        self::assertSame('1.2.0', $result->trace['catalog_version']);
        self::assertSame('1m', $result->trace['execution_timeframe']);
        self::assertSame(['1m'], $result->trace['mandatory_confirmations']);
        self::assertSame('ready', $result->trace['microstructure_input']['status']);
        self::assertSame($snapshot->inputHash, $result->trace['microstructure_input']['input_hash']);
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

    private function microLineage(): LineageContext
    {
        $catalogHash = 'sha256:' . (new \App\TradingCore\Rules\Catalog\ConditionCatalogLoader())->loadVersion('1.0.0')->stableHash();
        $config = [
            'schema_version' => 'effective-trading-config.v2',
            'mode' => ['mode_id' => 'micro_scalping', 'mode_version' => '1.0.0'],
            'setup' => ['setup_id' => 'micro_scalping.momentum_ofi.long', 'setup_version' => '1.0.0'],
            'exchange' => ['id' => 'okx'],
            'environment' => ['id' => 'mainnet'],
        ];
        $configHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($config, $catalogHash);
        $effective = CanonicalSnapshotMetadataFixture::enrich([
            'request' => [
                'mode_id' => 'micro_scalping', 'mode_version' => '1.0.0',
                'setup_id' => 'micro_scalping.momentum_ofi.long', 'setup_version' => '1.0.0',
                'exchange' => 'okx', 'environment' => 'mainnet', 'side' => 'long',
            ],
            'config' => $config,
            'config_hash' => $configHash,
            'condition_catalog_hash' => $catalogHash,
            'executable' => false,
            'blockers' => ['micro_scalping_contract_blocked'],
        ]);

        return LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-micro-runtime',
            'orchestration_set_id' => 'set-micro-runtime',
            'mode_id' => 'micro_scalping',
            'mode_version' => '1.0.0',
            'setup_id' => 'micro_scalping.momentum_ofi.long',
            'setup_version' => '1.0.0',
            'config_hash' => $configHash,
            'condition_catalog_hash' => $catalogHash,
            'side' => 'LONG',
            'exchange' => 'okx',
            'environment' => 'mainnet',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'dry_run' => true,
            'effective_config_reference' => 'effective-config:micro-runtime',
            'effective_config_snapshot' => $effective,
        ]);
    }

    private function executableMicroLineage(): LineageContext
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'micro_scalping', '1.1.0', 'micro_scalping.momentum_ofi.long', '1.1.0',
            'okx', 'mainnet', 'long', ShadowExecutionCapability::Paper,
        ));

        return LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-micro-shadow',
            'orchestration_set_id' => 'set-micro-shadow',
            'mode_id' => 'micro_scalping',
            'mode_version' => '1.1.0',
            'setup_id' => 'micro_scalping.momentum_ofi.long',
            'setup_version' => '1.1.0',
            'config_hash' => $snapshot->configHash,
            'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'side' => 'LONG',
            'exchange' => 'okx',
            'environment' => 'mainnet',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'decision_key' => 'decision-micro-shadow',
            'dry_run' => true,
            'effective_config_reference' => 'effective-config:micro-shadow',
            'effective_config_snapshot' => $snapshot->toArray(),
        ]);
    }

    private function microstructureSnapshot(): CanonicalMicrostructureSnapshot
    {
        $checksum = 'sha256:' . str_repeat('f', 64);

        return (new CanonicalMicrostructureEngine())->build(
            new CanonicalMicrostructurePolicy(60, 2, 5, 30, 3),
            new \DateTimeImmutable('2026-08-14T12:01:00.000000Z'),
            [new NormalizedBacktestPublicBook(
                str_repeat('a', 64), $checksum, 'mainnet', 'okx', 'BTCUSDT',
                '2026-08-14T12:00:59.000000Z', '2026-08-14T12:00:59.000000Z',
                '99.96', '10', '100.04', '12', 'contracts', '2', '3', 'ws_books',
            )],
            [
                new NormalizedBacktestPublicTrade(str_repeat('1', 64), $checksum, 'mainnet', 'okx', 'BTCUSDT', '1', '2026-08-14T12:00:10.000000Z', '2026-08-14T12:00:10.000000Z', 'buy', '100', '3', 'contracts'),
                new NormalizedBacktestPublicTrade(str_repeat('2', 64), $checksum, 'mainnet', 'okx', 'BTCUSDT', '2', '2026-08-14T12:00:30.000000Z', '2026-08-14T12:00:30.000000Z', 'sell', '100', '1', 'contracts'),
                new NormalizedBacktestPublicTrade(str_repeat('3', 64), $checksum, 'mainnet', 'okx', 'BTCUSDT', '3', '2026-08-14T12:00:55.000000Z', '2026-08-14T12:00:55.000000Z', 'buy', '100', '2', 'contracts'),
            ],
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function scalpingInputs(): array
    {
        return [
            '1h' => self::indicatorInput('1h', '2026-08-10T11:00:00Z'),
            '15m' => self::indicatorInput('15m', '2026-08-10T11:45:00Z', ['pullback_age_bars' => 1]),
            '5m' => self::indicatorInput('5m', '2026-08-10T11:55:00Z'),
            '1m' => self::indicatorInput('1m', '2026-08-10T11:59:00Z'),
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function indicatorInput(string $timeframe, string $klineTime, array $extra = []): array
    {
        $step = match ($timeframe) {
            '1m' => 60,
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
            '4h' => 14400,
            default => throw new \InvalidArgumentException('Unsupported test timeframe.'),
        };
        $current = (new \DateTimeImmutable($klineTime))->getTimestamp();
        $timestamps = [$current - $step, $current];

        return array_replace([
            'snapshot_identity' => [
                'timeframe' => $timeframe,
                'symbol' => 'BTCUSDT',
                'exchange' => 'fake',
                'environment' => 'test',
                'market_type' => 'perpetual',
            ],
            'kline_time' => $klineTime,
            'series_order' => 'oldest_to_newest',
            'ema_200_series' => [100.0, 101.0],
            'ema_200_series_timestamps' => $timestamps,
            'macd_hist_series' => [0.1, 0.2],
            'macd_hist_series_timestamps' => $timestamps,
            'macd_line_signal_series' => [-0.1, 0.1],
            'macd_line_signal_series_timestamps' => $timestamps,
        ], $extra);
    }

    /** @return list<ConditionInterface> */
    private function passingConditions(string $catalogVersion = '1.0.0'): array
    {
        $ids = (new \App\TradingCore\Rules\Catalog\ConditionCatalogLoader())->loadFile(
            dirname(__DIR__, 3) . '/config/trading/condition_catalog/' . $catalogVersion . '.yaml',
        )->conditionIds();

        return array_map(static fn (string $id): ConditionInterface => match ($id) {
            SpreadBpsLteCondition::NAME => new SpreadBpsLteCondition(),
            OrderFlowImbalanceGteCondition::NAME => new OrderFlowImbalanceGteCondition(),
            OrderFlowImbalanceLteCondition::NAME => new OrderFlowImbalanceLteCondition(),
            default => new class($id) implements ConditionInterface {
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
        }}, $ids);
    }
}
