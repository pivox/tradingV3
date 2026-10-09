<?php
declare(strict_types=1);
namespace App\Tests\TradingCore\Backtesting\Research;

use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Core\AtrCalculator;
use App\Indicator\Core\Momentum\Macd;
use App\Indicator\Core\Momentum\Rsi;
use App\Indicator\Core\Trend\Adx;
use App\Indicator\Core\Trend\Ema;
use App\Indicator\Core\Trend\Sma;
use App\Indicator\Core\Volatility\Bollinger;
use App\Indicator\Core\Volume\Vwap;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;
use App\TradingCore\Backtesting\Indicator\CanonicalPhpIndicatorCalculator;
use App\TradingCore\Backtesting\Research\ResearchCandle;
use App\TradingCore\Backtesting\Research\ResearchRollingWindows;
use App\TradingCore\Backtesting\Research\ResearchSignalSession;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(ResearchSignalSession::class)]
final class ResearchSignalSessionTest extends TestCase
{
    public static function calculator(): CanonicalPhpIndicatorCalculator
    {
        return new CanonicalPhpIndicatorCalculator(new Rsi(), new Macd(), new Ema(), new Adx(), new Sma(), new AtrCalculator(null), new Vwap(), new Bollinger());
    }

    public static function runtime(): CanonicalSetupRuleRuntime
    {
        $conditions = [];
        foreach (glob(dirname(__DIR__, 4) . '/src/Indicator/Condition/*Condition.php') as $file) {
            $class = 'App\\Indicator\\Condition\\' . basename($file, '.php');
            $reflection = new \ReflectionClass($class);
            if ($reflection->isInstantiable() && $reflection->implementsInterface(ConditionInterface::class)
                && ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) === 0) {
                $conditions[] = $reflection->newInstance();
            }
        }
        return new CanonicalSetupRuleRuntime($conditions);
    }

    public static function session(): ResearchSignalSession
    {
        return new ResearchSignalSession(new EffectiveTradingConfigResolver(), self::runtime(), self::calculator());
    }

    /** @return array<string, mixed> */
    public static function openFrame(string $symbol = 'BTCUSDT', int $minutes = 60030): array
    {
        return ['schema_version' => 'research-signal-open.v1', 'session_id' => 'training-' . $symbol, 'dataset_id' => 'fixture-' . $symbol, 'dataset_sha256' => str_repeat('a', 64), 'source_venue' => 'binance_usdm', 'source_network' => 'mainnet', 'market_type' => 'perpetual', 'symbol' => $symbol, 'start_ms' => 1667260800000, 'end_ms' => 1667260800000 + $minutes * 60000, 'score_start_ms' => 1667260800000];
    }

    public function testCanonicalRuleParityDeterminismAndNoLookahead(): void
    {
        self::assertTrue(class_exists(ResearchSignalSession::class));
        $session = self::session(); $opened = $session->open(self::openFrame());
        $windows = new ResearchRollingWindows('BTCUSDT', 1667260800000, 1667260800000 + 60030 * 60000);
        $first = null; $batch = [];
        for ($i = 0; $i < 60015; ++$i) {
            $record = ResearchRollingWindowsTest::candle($i);
            $windows->append(ResearchCandle::fromArray($record));
            $batch[] = $record;
            if (count($batch) < 15) { continue; }
            $results = $session->appendBatch(['schema_version' => 'research-signal-candles.v1', 'session_id' => 'training-BTCUSDT', 'candles' => $batch]);
            $batch = [];
            if ($results !== []) {
                $result = $results[0]; $first ??= $result;
                $contexts = [];
                foreach (ResearchRollingWindows::STEPS as $tf => $step) {
                    $bars = $windows->bars($tf); $last = $bars[array_key_last($bars)];
                    $contexts[$tf] = [...self::calculator()->calculateNumericSeries($windows->numericSeries($tf)), 'snapshot_identity' => ['timeframe' => $tf, 'symbol' => 'BTCUSDT', 'exchange' => 'fake', 'environment' => 'local', 'market_type' => 'perpetual'], 'kline_time' => ResearchSignalSession::instant($last['open_ms'])];
                }
                $golden = (new CanonicalBacktestRuleEvaluator(new EffectiveTradingConfigResolver(), self::runtime()))->evaluate(['schema_version' => 'canonical-backtest-rule-request.v1', 'request_id' => 'golden', 'effective_config_snapshot' => $opened['effective_config_snapshot'], 'symbol' => 'BTCUSDT', 'market_type' => 'perpetual', 'evaluated_at' => $result['evaluated_at'], 'indicators_by_timeframe' => $contexts])->toArray();
                self::assertSame($golden['passed'], $result['passed']);
                self::assertSame($golden['reason_code'], $result['reason_code']);
                self::assertSame(CanonicalBacktestRuleEvaluator::canonicalHash($golden['trace']), $result['trace_hash']);
                self::assertSame(CanonicalBacktestRuleEvaluator::canonicalHash($contexts), $result['numeric_context_hash']);
                self::assertSame('binance_usdm', $result['source']['source_venue']);
                self::assertSame('fake', $result['execution']['exchange']);
                self::assertIsBool($result['verdicts']['sections']['regime']['passed']);
                foreach ($result['contexts'] as $tf => $context) { self::assertLessThanOrEqual($result['evaluated_ms'], $context['available_ms']); }
            }
        }
        self::assertNotNull($first);
        $retained = $first;
        for ($i = 60015; $i < 60030; ++$i) { $session->appendBatch(['schema_version' => 'research-signal-candles.v1', 'session_id' => 'training-BTCUSDT', 'candles' => [ResearchRollingWindowsTest::candle($i)]]); }
        self::assertSame($retained, $first);
        $summary = $session->close(['schema_version' => 'research-signal-close.v1', 'session_id' => 'training-BTCUSDT']);
        self::assertSame('complete', $summary['completion']);
        self::assertSame(60030, $summary['consumed_candles']);
        self::assertSame(3, $summary['evaluated_ticks']);
        self::assertSame(3999, $summary['warmup_unavailable']);
    }

    public function testTenSymbolsHaveAuthenticSeparateExecutionIdentityAndPartialClose(): void
    {
        self::assertTrue(class_exists(ResearchSignalSession::class));
        foreach (ResearchCandle::SYMBOLS as $symbol) {
            $session = self::session(); $opened = $session->open(self::openFrame($symbol, 30));
            self::assertSame('binance_usdm', $opened['source']['source_venue']);
            self::assertSame('fake', $opened['execution']['exchange']);
            self::assertSame('backtest', $opened['execution']['execution_capability']);
            self::assertSame('day_trading', $opened['execution']['mode_id']);
            self::assertSame('1.1.0', $opened['execution']['setup_version']);
            self::assertSame(5.0, $opened['effective_config_snapshot']['config']['mode']['risk']['trade_budget']['value']);
            $session->appendBatch(['schema_version' => 'research-signal-candles.v1', 'session_id' => 'training-' . $symbol, 'candles' => [ResearchRollingWindowsTest::candle(0, $symbol)]]);
            $summary = $session->close(['schema_version' => 'research-signal-close.v1', 'session_id' => 'training-' . $symbol]);
            self::assertSame('partial', $summary['completion']);
        }
    }

    public function testSourceValidationCannotAcceptOverridesForgedHashesOrFutureEnd(): void
    {
        foreach (['source_venue' => 'okx', 'source_network' => 'testnet', 'market_type' => 'spot', 'dataset_sha256' => str_repeat('A', 64), 'effective_config_snapshot' => [], 'symbol' => 'SHIBUSDT', 'end_ms' => ResearchSignalSession::FROZEN_END_MS + 60000, 'score_start_ms' => 0] as $key => $value) {
            $frame = self::openFrame(); $frame[$key] = $value;
            try { self::session()->open($frame); self::fail('Invalid binding accepted: ' . $key); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testChunkingFreshSessionsAndRejectedBatchCannotChangeDecisions(): void
    {
        $all = [];
        foreach ([1440, 997] as $chunkSize) {
            $session = self::session(); $opened = $session->open(self::openFrame(minutes: 60060));
            $results = [];
            for ($offset = 0; $offset < 60060; $offset += $chunkSize) {
                $records = [];
                for ($i = $offset; $i < min(60060, $offset + $chunkSize); ++$i) { $records[] = ResearchRollingWindowsTest::candle($i); }
                $batch = ['schema_version' => 'research-signal-candles.v1', 'session_id' => 'training-BTCUSDT', 'candles' => $records];
                if ($offset === 0) {
                    $invalid = $batch; $invalid['candles'][1] = ResearchRollingWindowsTest::candle(2);
                    try { $session->appendBatch($invalid); self::fail('Gap in batch accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
                }
                array_push($results, ...$session->appendBatch($batch));
            }
            $summary = $session->close(['schema_version' => 'research-signal-close.v1', 'session_id' => 'training-BTCUSDT']);
            self::assertSame(5, $summary['evaluated_ticks']);
            self::assertSame($opened['baseline'], $results[0]['baseline']);
            self::assertNotNull($results[0]['trace']);
            self::assertNull($results[4]['trace']);
            self::assertSame(250.0, $opened['effective_config_snapshot']['config']['environment']['max_notional']);
            $all[] = [$results, $summary];
        }
        self::assertSame($all[0], $all[1]);
    }

    public function testChangedBaselineFileFailsClosedBeforeTheNextBatch(): void
    {
        $request = new \App\TradingCore\Config\EffectiveTradingConfigRequest('day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0', 'fake', 'local', 'long', \App\TradingCore\Execution\Enum\ShadowExecutionCapability::Backtest);
        $resolved = (new EffectiveTradingConfigResolver())->resolve($request);
        $temporary = tempnam(sys_get_temp_dir(), 'research-baseline-');
        self::assertNotFalse($temporary);
        try {
            file_put_contents($temporary, 'immutable-test-layer');
            $layers = $resolved->orderedLayers(); $layers[0]['path'] = $temporary;
            $provenance = $resolved->provenance();
            foreach ($provenance as &$layer) { if ($layer['type'] === 'base') { $layer['path'] = $temporary; } } unset($layer);
            $snapshot = new \App\TradingCore\Config\EffectiveTradingConfigSnapshot($request, $resolved->payload(), $resolved->configHash, $resolved->conditionCatalogHash, $layers, $provenance);
            $resolver = new class($snapshot) implements \App\TradingCore\Config\EffectiveTradingConfigResolverInterface {
                public function __construct(private readonly \App\TradingCore\Config\EffectiveTradingConfigSnapshot $snapshot) {}
                public function resolve(\App\TradingCore\Config\EffectiveTradingConfigRequest|string $request): \App\TradingCore\Config\EffectiveTradingConfigSnapshot { return $this->snapshot; }
            };
            $session = new ResearchSignalSession($resolver, self::runtime(), self::calculator());
            $session->open(self::openFrame(minutes: 15));
            file_put_contents($temporary, 'changed-test-layer');
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('research_baseline_file_changed');
            $session->appendBatch(['schema_version' => 'research-signal-candles.v1', 'session_id' => 'training-BTCUSDT', 'candles' => [ResearchRollingWindowsTest::candle(0)]]);
        } finally { unlink($temporary); }
    }

    public function testScoringWaitsUntilTheRequestedBoundaryAfterWarmup(): void
    {
        $session = self::session();
        $open = self::openFrame(minutes: 87841);
        $open['score_start_ms'] = 1672531200000; // January 1 2023, after the independent Nov/Dec warmup.
        $session->open($open);
        $results = [];
        for ($offset = 0; $offset < 87841; $offset += 1440) {
            $records = [];
            for ($i = $offset; $i < min(87841, $offset + 1440); ++$i) { $records[] = ResearchRollingWindowsTest::candle($i); }
            array_push($results, ...$session->appendBatch(['schema_version' => 'research-signal-candles.v1', 'session_id' => 'training-BTCUSDT', 'candles' => $records]));
        }
        self::assertCount(1, $results);
        self::assertSame(1672531200000, $results[0]['evaluated_ms']);
        $summary = $session->close(['schema_version' => 'research-signal-close.v1', 'session_id' => 'training-BTCUSDT']);
        self::assertGreaterThan(0, $summary['before_score_start']);
        self::assertSame(1, $summary['evaluated_ticks']);
        self::assertSame($summary['evaluated_ticks'], $summary['passed_rules'] + $summary['failed_rules']);
    }
}
