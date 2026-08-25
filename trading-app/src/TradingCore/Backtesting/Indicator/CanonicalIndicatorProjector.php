<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Indicator;

use App\TradingCore\MarketData\CanonicalIndicatorSnapshotIdentity;

final readonly class CanonicalIndicatorProjector implements CanonicalIndicatorProjectorInterface
{
    private const REQUEST_KEYS = [
        'schema_version',
        'request_id',
        'evaluated_at',
        'environment',
        'indicator_engine_version',
        'dataset_binding',
        'symbol',
        'requested_timeframes',
        'candles_by_timeframe',
    ];

    private const DATASET_BINDING_KEYS = [
        'dataset_id',
        'dataset_checksum',
        'candles_checksum',
        'quality_report_checksum',
        'source_checksum',
        'source_network',
        'market_data_venue',
        'market_type',
    ];

    /** @var list<string> */
    private const NATIVE_TIMEFRAMES = ['1m', '5m', '15m', '1h'];

    /** @var list<string> */
    private const OUTPUT_TIMEFRAMES = ['1m', '5m', '15m', '1h', '4h'];

    public function __construct(
        private CanonicalPhpIndicatorCalculator $calculator,
        private CanonicalFourHourAggregator $fourHourAggregator,
    ) {
    }

    /** @param array<string, mixed> $request */
    public function project(#[\SensitiveParameter] array $request): CanonicalIndicatorProjection
    {
        $this->assertExactKeys($request, self::REQUEST_KEYS, 'canonical_indicator_request_shape_invalid');
        if (($request['schema_version'] ?? null) !== 'canonical-indicator-projection-request.v1') {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_request_schema_invalid');
        }

        $requestId = $request['request_id'] ?? null;
        if (!\is_string($requestId)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D', $requestId) !== 1
        ) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_request_id_invalid');
        }

        $evaluatedAt = $request['evaluated_at'] ?? null;
        if (!\is_string($evaluatedAt)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D', $evaluatedAt) !== 1
        ) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_evaluated_at_invalid');
        }
        $instant = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $evaluatedAt,
            new \DateTimeZone('UTC'),
        );
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (!$instant instanceof \DateTimeImmutable
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        ) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_evaluated_at_invalid');
        }

        $environment = $request['environment'] ?? null;
        if (!\is_string($environment) || !\in_array($environment, ['local', 'test'], true)) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_environment_invalid');
        }
        if (($request['indicator_engine_version'] ?? null) !== 'php_fallback_v1') {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_engine_invalid');
        }

        $symbol = $request['symbol'] ?? null;
        if (!\is_string($symbol) || !\in_array($symbol, ['BTCUSDT', 'ETHUSDT'], true)) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_symbol_invalid');
        }

        $binding = $this->datasetBinding($request['dataset_binding'] ?? null);
        $timeframes = $this->requestedTimeframes($request['requested_timeframes'] ?? null);
        $candlesByTimeframe = $request['candles_by_timeframe'] ?? null;
        if (!\is_array($candlesByTimeframe)
            || array_is_list($candlesByTimeframe)
            || array_keys($candlesByTimeframe) !== $this->sourceTimeframes($timeframes)
        ) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_candles_shape_invalid');
        }

        $sourceBinding = [
            'source_network' => $binding['source_network'],
            'market_data_venue' => $binding['market_data_venue'],
            'market_type' => $binding['market_type'],
        ];
        $fourHourWindow = null;
        if (\in_array('4h', $timeframes, true)) {
            $hourlyRecords = $candlesByTimeframe['1h'];
            if (!\is_array($hourlyRecords)) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_candles_shape_invalid');
            }
            /** @var list<array<string, mixed>> $hourlyRecords */
            $fourHourWindow = $this->fourHourAggregator->aggregate(
                $this->fourHourSourceRecords(
                    $hourlyRecords,
                    $sourceBinding,
                    $symbol,
                    $evaluatedAt,
                ),
                $sourceBinding,
                $symbol,
                $evaluatedAt,
            );
        }

        $snapshots = [];
        foreach ($timeframes as $timeframe) {
            if ($timeframe === '4h') {
                if (!$fourHourWindow instanceof CanonicalIndicatorWindow) {
                    throw new \LogicException('Four-hour window was not aggregated.');
                }
                $window = $fourHourWindow;
            } else {
                $records = $candlesByTimeframe[$timeframe];
                if (!\is_array($records)) {
                    throw new CanonicalIndicatorProjectionException('canonical_indicator_candles_shape_invalid');
                }
                if ($timeframe === '1h' && $fourHourWindow instanceof CanonicalIndicatorWindow) {
                    $records = array_slice($records, -250);
                }
                /** @var list<array<string, mixed>> $records */
                $window = new CanonicalIndicatorWindow(
                    $records,
                    $sourceBinding,
                    $symbol,
                    $timeframe,
                    $evaluatedAt,
                );
            }
            $latest = $window->candles()[array_key_last($window->candles())];
            $snapshots[$timeframe] = [
                ...$this->calculator->calculate($window),
                'snapshot_identity' => (new CanonicalIndicatorSnapshotIdentity(
                    $timeframe,
                    $symbol,
                    'fake',
                    $environment,
                    'perpetual',
                ))->toArray(),
                'kline_time' => $latest->openTimestamp()->format('Y-m-d\TH:i:s.u\Z'),
                'window_hash' => $window->windowHash(),
                'indicator_engine_version' => 'php_fallback_v1',
            ];
        }

        $normalizedRequest = [
            'schema_version' => 'canonical-indicator-projection-request.v1',
            'request_id' => $requestId,
            'evaluated_at' => $evaluatedAt,
            'environment' => $environment,
            'indicator_engine_version' => 'php_fallback_v1',
            'dataset_binding' => $binding,
            'symbol' => $symbol,
            'requested_timeframes' => $timeframes,
            'candles_by_timeframe' => $candlesByTimeframe,
        ];

        return CanonicalIndicatorProjection::fromValidatedRequest($normalizedRequest, $snapshots);
    }

    /**
     * @param list<array<string, mixed>> $hourlyRecords
     * @param array<string, mixed>       $sourceBinding
     * @return list<array<string, mixed>>
     */
    private function fourHourSourceRecords(
        array $hourlyRecords,
        array $sourceBinding,
        string $symbol,
        string $evaluatedAt,
    ): array {
        if (!array_is_list($hourlyRecords)
            || \count($hourlyRecords) < 1000
            || \count($hourlyRecords) > 1003
        ) {
            throw new CanonicalIndicatorProjectionException(
                'canonical_indicator_four_hour_count_invalid',
            );
        }
        if (\count($hourlyRecords) > 1000) {
            new CanonicalIndicatorWindow(
                array_slice($hourlyRecords, -250),
                $sourceBinding,
                $symbol,
                '1h',
                $evaluatedAt,
            );
        }

        return array_slice($hourlyRecords, 0, 1000);
    }

    /** @return array{dataset_id:string,dataset_checksum:string,candles_checksum:string,quality_report_checksum:string,source_checksum:string,source_network:string,market_data_venue:string,market_type:string} */
    private function datasetBinding(mixed $value): array
    {
        if (!\is_array($value) || array_is_list($value)) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_dataset_binding_invalid');
        }
        $this->assertExactKeys($value, self::DATASET_BINDING_KEYS, 'canonical_indicator_dataset_binding_invalid');
        foreach (['dataset_checksum', 'candles_checksum', 'quality_report_checksum', 'source_checksum'] as $key) {
            if (!\is_string($value[$key])
                || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $value[$key]) !== 1
            ) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_dataset_binding_invalid');
            }
        }
        if (!\is_string($value['dataset_id'])
            || $value['dataset_id'] !== 'backtest-dataset-' . substr($value['dataset_checksum'], 7)
            || !\is_string($value['source_network'])
            || !\in_array($value['source_network'], ['mainnet', 'testnet'], true)
            || !\is_string($value['market_data_venue'])
            || !\in_array($value['market_data_venue'], ['okx', 'hyperliquid'], true)
            || $value['market_type'] !== 'perpetual'
        ) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_dataset_binding_invalid');
        }

        /** @var array{dataset_id:string,dataset_checksum:string,candles_checksum:string,quality_report_checksum:string,source_checksum:string,source_network:string,market_data_venue:string,market_type:string} $value */
        return $value;
    }

    /** @return list<string> */
    private function requestedTimeframes(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value) || $value === []) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_requested_timeframes_invalid');
        }
        $expected = [];
        foreach (self::OUTPUT_TIMEFRAMES as $timeframe) {
            if (\in_array($timeframe, $value, true)) {
                $expected[] = $timeframe;
            }
        }
        if ($value !== $expected) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_requested_timeframes_invalid');
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * @param list<string> $requested
     *
     * @return list<string>
     */
    private function sourceTimeframes(array $requested): array
    {
        $expected = [];
        foreach (self::NATIVE_TIMEFRAMES as $timeframe) {
            if (\in_array($timeframe, $requested, true)
                || ($timeframe === '1h' && \in_array('4h', $requested, true))
            ) {
                $expected[] = $timeframe;
            }
        }

        return $expected;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>            $expected
     */
    private function assertExactKeys(array $value, array $expected, string $reason): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new CanonicalIndicatorProjectionException($reason);
        }
    }
}
