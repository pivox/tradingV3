<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Policy;

use App\Common\Enum\Timeframe;
use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Condition\ConditionResult;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntimeResult;
use App\Trading\Lineage\LineageContext;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\Rules\Catalog\ConditionCatalogException;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * kline_time is the OPEN of the newest closed candle. Its freshness is measured from the
 * candle close (open + timeframe duration), then the catalog window applies.
 */
#[CoversClass(CanonicalSetupRuleRuntime::class)]
#[CoversClass(CanonicalSetupRuleRuntimeResult::class)]
final class CanonicalSetupRuleRuntimeCandleCloseFreshnessTest extends TestCase
{
    private const EVALUATED_AT = '2026-08-10T10:00:00Z';

    /** Opens of the newest candles closed at 10:00, in the day_trading required order. */
    private const LAST_CLOSED_OPENS = [
        '4h' => '2026-08-10T04:00:00Z',
        '1h' => '2026-08-10T09:00:00Z',
        '15m' => '2026-08-10T09:45:00Z',
        '5m' => '2026-08-10T09:55:00Z',
        '1m' => '2026-08-10T09:59:00Z',
    ];

    public function testCandlesClosedAtTheEvaluationInstantAreFresh(): void
    {
        $result = $this->runtime()->evaluate($this->dayTradingLineage(), $this->inputs(), $this->evaluatedAt());

        self::assertTrue($result->passed, $result->reasonCode);
        self::assertSame('setup_rules_passed', $result->reasonCode);
    }

    public function testFreshnessWindowStartsAtTheCandleCloseForEveryTimeframe(): void
    {
        $baseline = $this->runtime()->evaluate($this->dayTradingLineage(), $this->inputs(), $this->evaluatedAt());
        $catalog = (new ConditionCatalogLoader())->loadVersion((string) $baseline->trace['catalog_version']);

        foreach (array_keys(self::LAST_CLOSED_OPENS) as $timeframe) {
            $duration = Timeframe::from($timeframe)->getStepInSeconds();
            $window = $catalog->freshnessSeconds('indicator_snapshot', $timeframe);
            // Closed exactly $window seconds before the evaluation: the last fresh instant.
            $boundaryOpen = $this->evaluatedAt()->modify(sprintf('-%d seconds', $duration + $window));

            $fresh = $this->runtime()->evaluate(
                $this->dayTradingLineage(),
                $this->inputs([$timeframe => $boundaryOpen]),
                $this->evaluatedAt(),
            );
            $stale = $this->runtime()->evaluate(
                $this->dayTradingLineage(),
                $this->inputs([$timeframe => $boundaryOpen->modify('-1 second')]),
                $this->evaluatedAt(),
            );

            self::assertTrue($fresh->passed, $timeframe . ': ' . $fresh->reasonCode);
            self::assertSame('critical_timeframe_stale', $stale->reasonCode, $timeframe);
            self::assertSame($timeframe, $stale->trace['rejection']['timeframe'], $timeframe);
            self::assertSame('outside_freshness_window', $stale->trace['rejection']['cause'], $timeframe);
        }
    }

    public function testACandleThatHasNotClosedYetIsNotObservable(): void
    {
        foreach (array_keys(self::LAST_CLOSED_OPENS) as $timeframe) {
            $duration = Timeframe::from($timeframe)->getStepInSeconds();
            // Closes one second after the evaluation: still in progress.
            $openCandle = $this->evaluatedAt()->modify(sprintf('-%d seconds', $duration - 1));

            $result = $this->runtime()->evaluate(
                $this->dayTradingLineage(),
                $this->inputs([$timeframe => $openCandle]),
                $this->evaluatedAt(),
            );

            self::assertSame('critical_timeframe_stale', $result->reasonCode, $timeframe);
            self::assertSame($timeframe, $result->trace['rejection']['timeframe'], $timeframe);
            self::assertSame('outside_freshness_window', $result->trace['rejection']['cause'], $timeframe);
        }
    }

    public function testEveryTimeframeReferencedByCatalogsAndContractsHasACandleDuration(): void
    {
        $root = dirname(__DIR__, 3) . '/config/trading';
        /** @var array<string, list<string>> $timeframes */
        $timeframes = [];
        foreach (glob($root . '/condition_catalog/*.yaml') ?: [] as $file) {
            $document = Yaml::parseFile($file);
            foreach (array_keys($document['input_freshness_seconds']['indicator_snapshot'] ?? []) as $timeframe) {
                $timeframes[(string) $timeframe][] = $file;
            }
            self::collectTimeframes($document, [], $file, $timeframes);
        }
        foreach ([
            ...(glob($root . '/setup_contract/*/*.yaml') ?: []),
            ...(glob($root . '/mode_contract/*/*.yaml') ?: []),
        ] as $file) {
            self::collectTimeframes(Yaml::parseFile($file), [], $file, $timeframes);
        }
        // "global" names the effective-config input, not a candle timeframe.
        unset($timeframes['global']);

        foreach (array_keys(self::LAST_CLOSED_OPENS) as $timeframe) {
            self::assertArrayHasKey($timeframe, $timeframes);
        }
        foreach ($timeframes as $timeframe => $files) {
            self::assertNotNull(
                Timeframe::tryFrom((string) $timeframe)?->getStepInSeconds(),
                sprintf('Timeframe "%s" referenced by %s has no candle duration.', $timeframe, $files[0]),
            );
        }
    }

    /**
     * A required timeframe comes from a validated mode contract (ModeContractValidator only
     * accepts 4h, 1h, 15m, 5m and 1m), so "timeframe_duration_unknown" cannot be reached
     * through evaluate(): the helper itself is checked, and an unknown extra input still
     * fails closed exactly as before (no freshness contract in the catalog).
     */
    public function testAnUnknownTimeframeHasNoCandleCloseAndStaysFailClosed(): void
    {
        $runtime = $this->runtime();
        $candleClose = new \ReflectionMethod(CanonicalSetupRuleRuntime::class, 'candleClose');
        $open = new \DateTimeImmutable('2026-08-10T09:00:00Z');
        foreach (['2h', '3m', 'global', ''] as $timeframe) {
            self::assertNull($candleClose->invoke($runtime, $open, $timeframe), $timeframe);
        }
        self::assertEquals(new \DateTimeImmutable('2026-08-10T10:00:00Z'), $candleClose->invoke($runtime, $open, '1h'));

        $this->expectException(ConditionCatalogException::class);
        $runtime->evaluate(
            $this->dayTradingLineage(),
            [
                ...$this->inputs(),
                '2h' => self::indicatorInput('2h', new \DateTimeImmutable('2026-08-10T08:00:00Z'), 7200),
            ],
            $this->evaluatedAt(),
        );
    }

    /**
     * @param list<string> $path
     * @param array<string, list<string>> $timeframes
     */
    private static function collectTimeframes(mixed $node, array $path, string $file, array &$timeframes): void
    {
        if (\is_array($node)) {
            foreach ($node as $key => $child) {
                self::collectTimeframes($child, [...$path, (string) $key], $file, $timeframes);
            }

            return;
        }
        if (!\is_string($node)) {
            return;
        }
        $named = array_values(array_filter($path, static fn (string $segment): bool => !ctype_digit($segment)));
        $last = $named[\count($named) - 1] ?? null;
        $parent = $named[\count($named) - 2] ?? null;
        if (\in_array($last, ['timeframe', 'timeframes'], true)
            || $parent === 'timeframes'
            || ($last === 'value' && \in_array($parent, ['execution_timeframe', 'mandatory_confirmations'], true))
        ) {
            $timeframes[$node][] = $file;
        }
    }

    private function evaluatedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::EVALUATED_AT);
    }

    /**
     * @param array<string, \DateTimeImmutable> $opens
     * @return array<string, array<string, mixed>>
     */
    private function inputs(array $opens = []): array
    {
        $inputs = [];
        foreach (self::LAST_CLOSED_OPENS as $timeframe => $open) {
            $inputs[$timeframe] = self::indicatorInput(
                $timeframe,
                $opens[$timeframe] ?? new \DateTimeImmutable($open),
                extra: $timeframe === '1h' ? ['adx' => 25.0] : [],
            );
        }

        return $inputs;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function indicatorInput(
        string $timeframe,
        \DateTimeImmutable $klineOpen,
        ?int $step = null,
        array $extra = [],
    ): array {
        $step ??= Timeframe::from($timeframe)->getStepInSeconds();
        $current = $klineOpen->getTimestamp();
        $timestamps = [$current - $step, $current];

        return array_replace([
            'snapshot_identity' => [
                'timeframe' => $timeframe,
                'symbol' => 'BTCUSDT',
                'exchange' => 'fake',
                'environment' => 'test',
                'market_type' => 'perpetual',
            ],
            'kline_time' => $klineOpen->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'series_order' => 'oldest_to_newest',
            'ema_200_series' => [100.0, 101.0],
            'ema_200_series_timestamps' => $timestamps,
            'macd_hist_series' => [0.1, 0.2],
            'macd_hist_series_timestamps' => $timestamps,
            'macd_line_signal_series' => [-0.1, 0.1],
            'macd_line_signal_series_timestamps' => $timestamps,
        ], $extra);
    }

    private function runtime(): CanonicalSetupRuleRuntime
    {
        return new CanonicalSetupRuleRuntime($this->passingConditions());
    }

    private function dayTradingLineage(): LineageContext
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'fake', 'test', 'long', ShadowExecutionCapability::Fake,
        ));

        return LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-day-trading-close-freshness',
            'orchestration_set_id' => 'set-day-trading-close-freshness',
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
            'effective_config_reference' => 'effective-config:day-trading-close-freshness',
            'effective_config_snapshot' => $snapshot->toArray(),
        ]);
    }

    /** @return list<ConditionInterface> */
    private function passingConditions(): array
    {
        $ids = (new ConditionCatalogLoader())->loadFile(
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
