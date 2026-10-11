<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting;

use App\Tests\TradingCore\Backtesting\Research\ResearchSignalSessionTest;
use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorNumericSeries;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The canonical indicator projection alone proves the chronology the swing breakouts require. */
#[CoversNothing]
final class SwingBreakoutCanonicalRuntimeTest extends TestCase
{
    private const EVALUATED = 1677628800; // 2023-03-01T00:00:00Z, a 4h boundary
    private const STEPS = ['1m' => 60, '5m' => 300, '15m' => 900, '1h' => 3600, '4h' => 14400];

    /** @return iterable<string, array{string, string, float}> */
    public static function breakouts(): iterable
    {
        yield 'long above the prior 30-bar high' => ['swing.breakout.long', 'long', 0.1];
        yield 'short below the prior 30-bar low' => ['swing.breakout.short', 'short', -0.1];
    }

    #[DataProvider('breakouts')]
    public function testCalculatorOutputPassesTheStrictBreakoutRules(string $setupId, string $side, float $slope): void
    {
        $contexts = self::contexts($slope);
        self::assertSame(array_slice($contexts['4h']['series_timestamps'], -60), $contexts['4h']['high_series_timestamps']);

        $passed = self::evaluate($setupId, $side, $contexts);
        self::assertTrue($passed['passed'], $passed['reason_code']);
        self::assertSame('setup_rules_passed', $passed['reason_code']);

        // Without its own timestamps the series cannot prove chronology and the setup fails closed.
        $key = $side === 'long' ? 'high_series_timestamps' : 'low_series_timestamps';
        unset($contexts['4h'][$key]);
        self::assertFalse(self::evaluate($setupId, $side, $contexts)['passed']);
    }

    /**
     * @param array<string, array<string, mixed>> $contexts
     * @return array<string, mixed>
     */
    private static function evaluate(string $setupId, string $side, array $contexts): array
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'swing', '1.0.0', $setupId, '1.0.0', 'fake', 'local', $side, ShadowExecutionCapability::Backtest,
        ));

        return (new CanonicalBacktestRuleEvaluator(new EffectiveTradingConfigResolver(), ResearchSignalSessionTest::runtime()))->evaluate([
            'schema_version' => 'canonical-backtest-rule-request.v1',
            'request_id' => 'swing-' . $side,
            'effective_config_snapshot' => $snapshot->toArray(),
            'symbol' => 'BTCUSDT',
            'market_type' => 'perpetual',
            'evaluated_at' => gmdate('Y-m-d\TH:i:s', self::EVALUATED) . '.000000Z',
            'indicators_by_timeframe' => $contexts,
        ])->toArray();
    }

    /** @return array<string, array<string, mixed>> */
    private static function contexts(float $slope): array
    {
        $contexts = [];
        foreach (self::STEPS as $timeframe => $step) {
            $opens = $highs = $lows = $closes = $volumes = $timestamps = [];
            for ($bar = 0; $bar < 250; ++$bar) {
                $close = 100.0 + $slope * ($bar + 1);
                $open = $close - $slope;
                $opens[] = $open;
                $closes[] = $close;
                $highs[] = max($open, $close) + 0.01;
                $lows[] = min($open, $close) - 0.01;
                $volumes[] = 1.0;
                $timestamps[] = self::EVALUATED - $step * (250 - $bar);
            }
            $contexts[$timeframe] = [
                ...ResearchSignalSessionTest::calculator()->calculateNumericSeries(
                    new CanonicalIndicatorNumericSeries($opens, $highs, $lows, $closes, $volumes, $timestamps),
                ),
                'snapshot_identity' => ['timeframe' => $timeframe, 'symbol' => 'BTCUSDT', 'exchange' => 'fake', 'environment' => 'local', 'market_type' => 'perpetual'],
                'kline_time' => gmdate('Y-m-d\TH:i:s', $timestamps[249]) . '.000000Z',
            ];
        }

        return $contexts;
    }
}
