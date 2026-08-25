<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting\Indicator;

use App\Indicator\Core\AtrCalculator;
use App\Indicator\Core\Momentum\Macd;
use App\Indicator\Core\Momentum\Rsi;
use App\Indicator\Core\Trend\Adx;
use App\Indicator\Core\Trend\Ema;
use App\Indicator\Core\Trend\Sma;
use App\Indicator\Core\Volatility\Bollinger;
use App\Indicator\Core\Volume\Vwap;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;
use App\TradingCore\Backtesting\Indicator\CanonicalFourHourAggregator;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjection;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjectionException;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjector;
use App\TradingCore\Backtesting\Indicator\CanonicalPhpIndicatorCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalIndicatorProjector::class)]
#[CoversClass(CanonicalIndicatorProjection::class)]
final class CanonicalIndicatorProjectorTest extends TestCase
{
    public function testReturnsCanonicalProjectionResultObject(): void
    {
        self::assertInstanceOf(CanonicalIndicatorProjection::class, $this->projector()->project($this->request(['1m'])));
    }

    public function testResultHasExactSchemaAndCanonicalEvidenceHashes(): void
    {
        $request = $this->request(['1m']);
        $result = $this->projector()->project($request)->toArray();

        self::assertSame([
            'schema_version', 'request_id', 'evaluated_at', 'environment', 'indicator_engine_version',
            'dataset_binding', 'symbol', 'requested_timeframes', 'snapshots_by_timeframe',
            'input_hash', 'result_hash',
        ], array_keys($result));
        self::assertSame('canonical-indicator-projection-result.v1', $result['schema_version']);
        self::assertSame(CanonicalBacktestRuleEvaluator::canonicalHash($request), $result['input_hash']);

        $withoutResultHash = $result;
        unset($withoutResultHash['result_hash']);
        self::assertSame(
            CanonicalBacktestRuleEvaluator::canonicalHash($withoutResultHash),
            $result['result_hash'],
        );
    }

    public function testInputAndResultHashesUseTheSameIntegralFloatCanonicalProtocol(): void
    {
        $projection = CanonicalIndicatorProjection::fromValidatedRequest([
            'schema_version' => 'canonical-indicator-projection-request.v1',
            'request_id' => 'integral-float-hash-probe',
            'evaluated_at' => '2026-02-12T00:00:00.000000Z',
            'environment' => 'test',
            'indicator_engine_version' => 'php_fallback_v1',
            'dataset_binding' => [],
            'symbol' => 'BTCUSDT',
            'requested_timeframes' => ['1m'],
            'candles_by_timeframe' => ['1m' => []],
        ], [
            '1m' => ['integral_float' => 1.0],
        ])->toArray();
        $withoutResultHash = $projection;
        unset($withoutResultHash['result_hash']);

        $sharedProtocolHash = CanonicalBacktestRuleEvaluator::canonicalHash($withoutResultHash);
        $paperJsonHash = 'sha256:' . hash('sha256', CanonicalJson::encode($withoutResultHash));

        self::assertNotSame($paperJsonHash, $sharedProtocolHash);
        self::assertSame($sharedProtocolHash, $projection['result_hash']);
    }

    public function testResultIsReadonlyAndToArrayIsDefensive(): void
    {
        $result = $this->projector()->project($this->request(['1m']));
        $reflection = new \ReflectionClass($result);
        $constructor = $reflection->getConstructor();
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());

        $expected = $result->toArray();
        $copy = $result->toArray();
        $copy['dataset_binding']['market_data_venue'] = 'forged';
        $copy['snapshots_by_timeframe']['1m']['snapshot_identity']['exchange'] = 'forged';

        self::assertSame($expected, $result->toArray());
    }

    public function testEverySubmittedCandleIsBoundIntoInputAndResultHashes(): void
    {
        $request = $this->request(['1m']);
        $mutatedRequest = $request;
        $mutatedRequest['candles_by_timeframe']['1m'][0]['volume'] = '10.01';

        $original = $this->projector()->project($request)->toArray();
        $mutated = $this->projector()->project($mutatedRequest)->toArray();

        self::assertNotSame($original['input_hash'], $mutated['input_hash']);
        self::assertNotSame($original['result_hash'], $mutated['result_hash']);
        self::assertSame(
            $original['snapshots_by_timeframe']['1m']['snapshot_identity'],
            $mutated['snapshots_by_timeframe']['1m']['snapshot_identity'],
        );
    }

    public function testProjectsOneNativeWindowWithFakeExecutionIdentityAndSourceEvidence(): void
    {
        $request = $this->request(['1m']);
        $result = $this->projector()->project($request)->toArray();

        self::assertSame([
            'schema_version', 'request_id', 'evaluated_at', 'environment', 'indicator_engine_version',
            'dataset_binding', 'symbol', 'requested_timeframes', 'snapshots_by_timeframe', 'input_hash', 'result_hash',
        ], array_keys($result));
        self::assertSame(
            array_intersect_key($request, array_flip(['request_id', 'evaluated_at', 'environment', 'indicator_engine_version', 'dataset_binding', 'symbol', 'requested_timeframes'])),
            array_intersect_key($result, array_flip(['request_id', 'evaluated_at', 'environment', 'indicator_engine_version', 'dataset_binding', 'symbol', 'requested_timeframes'])),
        );
        self::assertSame(['1m'], array_keys($result['snapshots_by_timeframe']));

        $snapshot = $result['snapshots_by_timeframe']['1m'];
        self::assertSame([
            'timeframe' => '1m',
            'symbol' => 'BTCUSDT',
            'exchange' => 'fake',
            'environment' => 'test',
            'market_type' => 'perpetual',
        ], $snapshot['snapshot_identity']);
        self::assertSame('okx', $result['dataset_binding']['market_data_venue']);
        self::assertNotSame($result['dataset_binding']['market_data_venue'], $snapshot['snapshot_identity']['exchange']);
        self::assertSame('2026-01-01T04:09:00.000000Z', $snapshot['kline_time']);
        self::assertMatchesRegularExpression('/\Asha256:[0-9a-f]{64}\z/D', $snapshot['window_hash']);
        self::assertSame('php_fallback_v1', $snapshot['indicator_engine_version']);
        self::assertSame(125.23, $snapshot['close']);
        self::assertArrayHasKey('rsi', $snapshot);
        self::assertArrayHasKey('macd_hist_series', $snapshot);
    }

    public function testProjectsMultipleNativeWindowsInRequestedDurationOrder(): void
    {
        $result = $this->projector()->project($this->request(['1m', '15m', '1h']))->toArray();

        self::assertSame(['1m', '15m', '1h'], array_keys($result['snapshots_by_timeframe']));
        self::assertSame('2026-01-01T04:09:00.000000Z', $result['snapshots_by_timeframe']['1m']['kline_time']);
        self::assertSame('2026-01-03T14:15:00.000000Z', $result['snapshots_by_timeframe']['15m']['kline_time']);
        self::assertSame('2026-01-11T09:00:00.000000Z', $result['snapshots_by_timeframe']['1h']['kline_time']);
    }

    public function testProjectsFourHourWindowFromExactlyOneThousandHourlySourceCandles(): void
    {
        $request = $this->request(['4h']);
        $result = $this->projector()->project($request)->toArray();

        self::assertSame(['4h'], $result['requested_timeframes']);
        self::assertSame(['4h'], array_keys($result['snapshots_by_timeframe']));

        $snapshot = $result['snapshots_by_timeframe']['4h'];
        self::assertSame([
            'timeframe' => '4h',
            'symbol' => 'BTCUSDT',
            'exchange' => 'fake',
            'environment' => 'test',
            'market_type' => 'perpetual',
        ], $snapshot['snapshot_identity']);
        self::assertSame('2026-02-11T12:00:00.000000Z', $snapshot['kline_time']);
        self::assertSame('php_fallback_v1', $snapshot['indicator_engine_version']);
        self::assertSame(200.23, $snapshot['close']);
        self::assertArrayHasKey('rsi', $snapshot);
        self::assertArrayHasKey('macd_hist_series', $snapshot);

        $derivedWindow = (new CanonicalFourHourAggregator())->aggregate(
            $request['candles_by_timeframe']['1h'],
            $this->sourceBinding($request),
            'BTCUSDT',
            $request['evaluated_at'],
        );
        self::assertSame($derivedWindow->windowHash(), $snapshot['window_hash']);
    }

    public function testProjectsNativeHourlySuffixAlongsideDerivedFourHourWindow(): void
    {
        $request = $this->request(['1h', '4h']);
        $request['candles_by_timeframe']['1h'] = $this->records('1h', 1002);
        $result = $this->projector()->project($request)->toArray();

        self::assertSame(['1h', '4h'], array_keys($result['snapshots_by_timeframe']));
        $nativeWindow = new \App\TradingCore\Backtesting\Indicator\CanonicalIndicatorWindow(
            array_slice($request['candles_by_timeframe']['1h'], -250),
            $this->sourceBinding($request),
            'BTCUSDT',
            '1h',
            $request['evaluated_at'],
        );
        self::assertSame($nativeWindow->windowHash(), $result['snapshots_by_timeframe']['1h']['window_hash']);
        self::assertSame('2026-02-11T17:00:00.000000Z', $result['snapshots_by_timeframe']['1h']['kline_time']);
        self::assertSame('2026-02-11T12:00:00.000000Z', $result['snapshots_by_timeframe']['4h']['kline_time']);
    }

    public function testUsesExactNativeSourceKeysWhenNativeAndDerivedOutputsCoexist(): void
    {
        $request = $this->request(['5m', '4h']);

        self::assertSame(['5m', '1h'], array_keys($request['candles_by_timeframe']));
        self::assertSame(['5m', '4h'], array_keys($this->projector()->project($request)->toArray()['snapshots_by_timeframe']));
    }

    #[DataProvider('invalidFourHourSourceCountProvider')]
    public function testRejectsInvalidFourHourHourlySourceCounts(int $count): void
    {
        $request = $this->request(['4h']);
        $request['candles_by_timeframe']['1h'] = array_slice($request['candles_by_timeframe']['1h'], 0, $count);
        if ($count === 1004) {
            $request['candles_by_timeframe']['1h'] = $this->records('1h', 1004);
        }

        $this->assertProjectionFailure($request, 'canonical_indicator_four_hour_count_invalid');
    }

    /** @return iterable<string, array{int}> */
    public static function invalidFourHourSourceCountProvider(): iterable
    {
        yield 'native-sized 250' => [250];
        yield 'one short 999' => [999];
        yield 'suffix too long 1004' => [1004];
    }

    public function testValidatesOldHourlyPrefixBeforeProjectingNativeSuffix(): void
    {
        $request = $this->request(['1h', '4h']);
        $request['candles_by_timeframe']['1h'][0]['market_data_venue'] = 'hyperliquid';

        $this->assertProjectionFailure($request, 'canonical_indicator_source_binding_mismatch');
    }

    public function testFourHourReplayIsDeterministic(): void
    {
        $request = $this->request(['1h', '4h']);

        $first = $this->projector()->project($request)->toArray();
        $second = $this->projector()->project($request)->toArray();

        self::assertSame($first, $second);
        self::assertSame(CanonicalJson::encode($first), CanonicalJson::encode($second));
    }

    public function testIdenticalReplayProducesIdenticalCanonicalResult(): void
    {
        $request = $this->request(['1m', '5m']);
        $first = $this->projector()->project($request)->toArray();
        $second = $this->projector()->project($request)->toArray();

        self::assertSame($first, $second);
        self::assertSame(CanonicalJson::encode($first), CanonicalJson::encode($second));
    }

    /** @param callable(array<string,mixed>&):void $mutate */
    #[DataProvider('invalidRequestProvider')]
    public function testRejectsInvalidRequests(callable $mutate, string $reason): void
    {
        $request = $this->request(['1m']);
        $mutate($request);

        try {
            $this->projector()->project($request);
            self::fail('Expected projection request rejection.');
        } catch (CanonicalIndicatorProjectionException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{callable(array<string,mixed>&):void,string}> */
    public static function invalidRequestProvider(): iterable
    {
        yield 'extra request field' => [static function (array &$request): void { $request['extra'] = true; }, 'canonical_indicator_request_shape_invalid'];
        yield 'missing request field' => [static function (array &$request): void { unset($request['symbol']); }, 'canonical_indicator_request_shape_invalid'];
        yield 'wrong request container type' => [static function (array &$request): void { $request['candles_by_timeframe'] = 'invalid'; }, 'canonical_indicator_candles_shape_invalid'];
        yield 'wrong schema' => [static function (array &$request): void { $request['schema_version'] = 'canonical-indicator-projection-request.v0'; }, 'canonical_indicator_request_schema_invalid'];
        yield 'invalid request id type' => [static function (array &$request): void { $request['request_id'] = 7; }, 'canonical_indicator_request_id_invalid'];
        yield 'unsafe request id' => [static function (array &$request): void { $request['request_id'] = '../projection'; }, 'canonical_indicator_request_id_invalid'];
        yield 'timestamp without strict microseconds' => [static function (array &$request): void { $request['evaluated_at'] = '2026-01-12T00:00:00Z'; }, 'canonical_indicator_evaluated_at_invalid'];
        yield 'timestamp offset' => [static function (array &$request): void { $request['evaluated_at'] = '2026-01-12T01:00:00.000000+01:00'; }, 'canonical_indicator_evaluated_at_invalid'];
        yield 'production environment' => [static function (array &$request): void { $request['environment'] = 'prod'; }, 'canonical_indicator_environment_invalid'];
        yield 'wrong engine' => [static function (array &$request): void { $request['indicator_engine_version'] = 'trader_v1'; }, 'canonical_indicator_engine_invalid'];
        yield 'unsupported symbol' => [static function (array &$request): void { $request['symbol'] = 'SOLUSDT'; }, 'canonical_indicator_symbol_invalid'];
        yield 'binding extra field' => [static function (array &$request): void { $request['dataset_binding']['extra'] = true; }, 'canonical_indicator_dataset_binding_invalid'];
        yield 'binding missing field' => [static function (array &$request): void { unset($request['dataset_binding']['source_checksum']); }, 'canonical_indicator_dataset_binding_invalid'];
        yield 'binding checksum type' => [static function (array &$request): void { $request['dataset_binding']['candles_checksum'] = 1; }, 'canonical_indicator_dataset_binding_invalid'];
        yield 'binding network' => [static function (array &$request): void { $request['dataset_binding']['source_network'] = 'fake'; }, 'canonical_indicator_dataset_binding_invalid'];
        yield 'binding venue' => [static function (array &$request): void { $request['dataset_binding']['market_data_venue'] = 'fake'; }, 'canonical_indicator_dataset_binding_invalid'];
        yield 'binding market type' => [static function (array &$request): void { $request['dataset_binding']['market_type'] = 'spot'; }, 'canonical_indicator_dataset_binding_invalid'];
        yield 'forged dataset relation' => [static function (array &$request): void { $request['dataset_binding']['dataset_id'] = 'backtest-dataset-' . str_repeat('f', 64); }, 'canonical_indicator_dataset_binding_invalid'];
        yield 'empty timeframes' => [static function (array &$request): void { $request['requested_timeframes'] = []; $request['candles_by_timeframe'] = []; }, 'canonical_indicator_requested_timeframes_invalid'];
        yield 'duplicate timeframes' => [static function (array &$request): void { $request['requested_timeframes'] = ['1m', '1m']; }, 'canonical_indicator_requested_timeframes_invalid'];
        yield 'timeframes out of order' => [static function (array &$request): void { $request['requested_timeframes'] = ['5m', '1m']; $request['candles_by_timeframe'] = ['5m' => $request['candles_by_timeframe']['1m'], '1m' => $request['candles_by_timeframe']['1m']]; }, 'canonical_indicator_requested_timeframes_invalid'];
        yield 'four hour out of duration order' => [static function (array &$request): void { $request = self::fourHourRequestFrom($request, ['4h', '1h']); }, 'canonical_indicator_requested_timeframes_invalid'];
        yield 'duplicate four hour' => [static function (array &$request): void { $request = self::fourHourRequestFrom($request, ['4h', '4h']); }, 'canonical_indicator_requested_timeframes_invalid'];
        yield 'timeframes scalar' => [static function (array &$request): void { $request['requested_timeframes'] = '1m'; }, 'canonical_indicator_requested_timeframes_invalid'];
        yield 'missing candles' => [static function (array &$request): void { $request['requested_timeframes'] = ['1m', '5m']; }, 'canonical_indicator_candles_shape_invalid'];
        yield 'extraneous candles' => [static function (array &$request): void { $request['candles_by_timeframe']['5m'] = $request['candles_by_timeframe']['1m']; }, 'canonical_indicator_candles_shape_invalid'];
        yield 'illegal derived source key' => [static function (array &$request): void { $request = self::fourHourRequestFrom($request, ['4h']); $request['candles_by_timeframe'] = ['4h' => $request['candles_by_timeframe']['1h']]; }, 'canonical_indicator_candles_shape_invalid'];
        yield 'extraneous native source for four hour' => [static function (array &$request): void { $request = self::fourHourRequestFrom($request, ['4h']); $request['candles_by_timeframe']['5m'] = $request['candles_by_timeframe']['1h']; }, 'canonical_indicator_candles_shape_invalid'];
        yield 'missing hourly source for four hour' => [static function (array &$request): void { $request = self::fourHourRequestFrom($request, ['4h']); $request['candles_by_timeframe'] = []; }, 'canonical_indicator_candles_shape_invalid'];
    }

    /**
     * @param list<string> $timeframes
     *
     * @return array<string, mixed>
     */
    private function request(array $timeframes): array
    {
        $datasetChecksum = 'sha256:' . str_repeat('a', 64);
        $candles = [];
        foreach (['1m', '5m', '15m'] as $timeframe) {
            if (\in_array($timeframe, $timeframes, true)) {
                $candles[$timeframe] = $this->records($timeframe);
            }
        }
        if (\in_array('1h', $timeframes, true) || \in_array('4h', $timeframes, true)) {
            $candles['1h'] = $this->records('1h', \in_array('4h', $timeframes, true) ? 1000 : 250);
        }

        return [
            'schema_version' => 'canonical-indicator-projection-request.v1',
            'request_id' => 'indicator-projection-0001',
            'evaluated_at' => '2026-02-12T00:00:00.000000Z',
            'environment' => 'test',
            'indicator_engine_version' => 'php_fallback_v1',
            'dataset_binding' => [
                'dataset_id' => 'backtest-dataset-' . substr($datasetChecksum, 7),
                'dataset_checksum' => $datasetChecksum,
                'candles_checksum' => 'sha256:' . str_repeat('b', 64),
                'quality_report_checksum' => 'sha256:' . str_repeat('c', 64),
                'source_checksum' => 'sha256:' . str_repeat('d', 64),
                'source_network' => 'mainnet',
                'market_data_venue' => 'okx',
                'market_type' => 'perpetual',
            ],
            'symbol' => 'BTCUSDT',
            'requested_timeframes' => $timeframes,
            'candles_by_timeframe' => $candles,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function records(string $timeframe, int $count = 250): array
    {
        $seconds = ['1m' => 60, '5m' => 300, '15m' => 900, '1h' => 3600][$timeframe];
        $start = new \DateTimeImmutable('2026-01-01T00:00:00.000000Z');
        $records = [];
        for ($index = 0; $index < $count; ++$index) {
            $open = 100.0 + ($index * 0.1) + (($index % 7) * 0.03);
            $close = $index === $count - 1 ? 100.33 + ($index * 0.1) : $open + ((($index % 9) - 4) * 0.02);
            $high = max($open, $close) + 0.17 + (($index % 5) * 0.01);
            $low = min($open, $close) - 0.13 - (($index % 3) * 0.01);
            $openAt = $start->modify('+' . ($index * $seconds) . ' seconds');
            $closeAt = $openAt->modify('+' . $seconds . ' seconds');
            $records[] = [
                'schema_version' => 'backtest-candle.v1',
                'source_record_id' => hash('sha256', $timeframe . '-projector-' . $index),
                'source_network' => 'mainnet',
                'market_data_venue' => 'okx',
                'market_type' => 'perpetual',
                'symbol' => 'BTCUSDT',
                'timeframe' => $timeframe,
                'open_at' => $openAt->format('Y-m-d\TH:i:s.u\Z'),
                'close_at' => $closeAt->format('Y-m-d\TH:i:s.u\Z'),
                'available_at' => $closeAt->format('Y-m-d\TH:i:s.u\Z'),
                'open' => $this->decimal($open),
                'high' => $this->decimal($high),
                'low' => $this->decimal($low),
                'close' => $this->decimal($close),
                'volume' => $this->decimal(10.0 + (($index * 13) % 29) + (($index % 4) * 0.25)),
                'complete' => true,
            ];
        }

        return $records;
    }

    private function projector(): CanonicalIndicatorProjector
    {
        return new CanonicalIndicatorProjector(new CanonicalPhpIndicatorCalculator(
            new Rsi(), new Macd(), new Ema(), new Adx(), new Sma(),
            new AtrCalculator(null), new Vwap(), new Bollinger(),
        ), new CanonicalFourHourAggregator());
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{source_network: mixed, market_data_venue: mixed, market_type: mixed}
     */
    private function sourceBinding(array $request): array
    {
        return [
            'source_network' => $request['dataset_binding']['source_network'],
            'market_data_venue' => $request['dataset_binding']['market_data_venue'],
            'market_type' => $request['dataset_binding']['market_type'],
        ];
    }

    /** @param array<string, mixed> $request */
    private function assertProjectionFailure(array $request, string $reason): void
    {
        try {
            $this->projector()->project($request);
            self::fail('Expected projection request rejection.');
        } catch (CanonicalIndicatorProjectionException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $request
     * @param list<string>         $timeframes
     *
     * @return array<string, mixed>
     */
    private static function fourHourRequestFrom(array $request, array $timeframes): array
    {
        $request['requested_timeframes'] = $timeframes;
        $request['candles_by_timeframe'] = ['1h' => array_fill(0, 1000, $request['candles_by_timeframe']['1m'][0])];

        return $request;
    }

    private function decimal(float $value): string
    {
        return rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
    }
}
