<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorCandle;

final class PaperCanonicalIndicatorDatasetBindingBuilder
{
    private const DATASET_BUILD_VERSION = 'backtest-dataset-builder.v1';
    private const MANIFEST_SCHEMA_VERSION = 'backtest-dataset-manifest.v1';
    private const QUALITY_SCHEMA_VERSION = 'backtest-dataset-quality.v1';
    private const SOURCE_SCHEMA_VERSION = 'paper-market-dataset.v2';

    /** @var array<string, int> */
    private const DURATIONS = ['1m' => 60, '5m' => 300, '15m' => 900, '1h' => 3600];

    /**
     * @param array<array-key, mixed> $candlesByTimeframe
     *
     * @return array{dataset_id:string,dataset_checksum:string,candles_checksum:string,quality_report_checksum:string,source_checksum:string,source_network:string,market_data_venue:string,market_type:string}
     */
    public function build(
        PaperExecutionCell $cell,
        string $symbol,
        string $sourceBuildVersion,
        string $sourceEventsFileSha256,
        array $candlesByTimeframe,
    ): array {
        try {
            if (!$cell->isModern()
                || !\in_array($symbol, ['BTCUSDT', 'ETHUSDT'], true)
                || $sourceBuildVersion === ''
                || trim($sourceBuildVersion) !== $sourceBuildVersion
                || preg_match('/\A[0-9a-f]{64}\z/D', $sourceEventsFileSha256) !== 1
                || $candlesByTimeframe === []
                || array_is_list($candlesByTimeframe)
            ) {
                throw new \LogicException('paper_canonical_indicator_dataset_invalid');
            }

            $records = [];
            foreach ($candlesByTimeframe as $timeframe => $window) {
                if (!\is_string($timeframe)
                    || !isset(self::DURATIONS[$timeframe])
                    || !\is_array($window)
                    || !array_is_list($window)
                    || $window === []
                ) {
                    throw new \LogicException('paper_canonical_indicator_dataset_invalid');
                }
                foreach ($window as $record) {
                    if (!\is_array($record) || array_is_list($record)) {
                        throw new \LogicException('paper_canonical_indicator_dataset_invalid');
                    }
                    $candle = CanonicalIndicatorCandle::fromArray($record);
                    if ($candle->sourceNetwork !== $cell->network->value
                        || $candle->marketDataVenue !== $cell->marketDataVenue->value
                        || $candle->marketType !== 'perpetual'
                        || $candle->symbol !== $symbol
                        || $candle->timeframe !== $timeframe
                    ) {
                        throw new \LogicException('paper_canonical_indicator_dataset_invalid');
                    }
                    $records[] = $candle;
                }
            }
            usort($records, self::compare(...));
            $streams = $this->streams($records);
            $canonicalRecords = array_map(
                static fn (CanonicalIndicatorCandle $candle): array => $candle->toArray(),
                $records,
            );
            $candlesPayload = '';
            foreach ($canonicalRecords as $record) {
                $candlesPayload .= CanonicalJson::encode($record) . "\n";
            }
            $candlesChecksum = self::checksum($candlesPayload);

            $recordCount = count($records);
            $qualityReport = [
                'schema_version' => self::QUALITY_SCHEMA_VERSION,
                'input_count' => $recordCount,
                'accepted_count' => $recordCount,
                'streams' => $streams,
                'exact_duplicate_count' => 0,
                'conflicting_duplicate_count' => 0,
                'missing_ranges' => [],
                'quality_flags' => [],
            ];
            $qualityReportChecksum = self::checksum(CanonicalJson::encode($qualityReport) . "\n");

            $timeframes = array_values(array_unique(array_map(
                static fn (CanonicalIndicatorCandle $candle): string => $candle->timeframe,
                $records,
            )));
            usort($timeframes, static fn (string $left, string $right): int => self::DURATIONS[$left] <=> self::DURATIONS[$right]);
            $symbols = array_values(array_unique(array_map(
                static fn (CanonicalIndicatorCandle $candle): string => $candle->symbol,
                $records,
            )));
            sort($symbols, SORT_STRING);
            $sourceChecksum = 'sha256:' . $sourceEventsFileSha256;
            $manifestCore = [
                'build_version' => self::DATASET_BUILD_VERSION,
                'coverage' => [
                    'end_at' => max(array_map(
                        static fn (CanonicalIndicatorCandle $candle): string => $candle->closeAt,
                        $records,
                    )),
                    'record_count' => $recordCount,
                    'start_at' => min(array_map(
                        static fn (CanonicalIndicatorCandle $candle): string => $candle->openAt,
                        $records,
                    )),
                    'streams' => array_map(static fn (array $stream): array => [
                        'first_open_at' => $stream['first_open_at'],
                        'last_close_at' => $stream['last_close_at'],
                        'market_data_venue' => $stream['market_data_venue'],
                        'market_type' => $stream['market_type'],
                        'record_count' => $stream['observed_count'],
                        'symbol' => $stream['symbol'],
                        'timeframe' => $stream['timeframe'],
                    ], $streams),
                    'symbols' => $symbols,
                    'timeframes' => $timeframes,
                ],
                'quality_flags' => [],
                'quality_report_schema_version' => self::QUALITY_SCHEMA_VERSION,
                'record_schema_version' => CanonicalIndicatorCandle::SCHEMA_VERSION,
                'schema_version' => self::MANIFEST_SCHEMA_VERSION,
                'source' => [
                    'source' => 'paper_market_dataset',
                    'source_schema_version' => self::SOURCE_SCHEMA_VERSION,
                    'source_build_version' => $sourceBuildVersion,
                    'source_checksum' => $sourceChecksum,
                    'source_network' => $cell->network->value,
                    'market_data_venue' => $cell->marketDataVenue->value,
                    'market_type' => 'perpetual',
                ],
            ];
            $datasetChecksum = self::checksum(CanonicalJson::encode([
                'candles_checksum' => $candlesChecksum,
                'manifest_core' => $manifestCore,
                'quality_report_checksum' => $qualityReportChecksum,
            ]));

            return [
                'dataset_id' => 'backtest-dataset-' . substr($datasetChecksum, 7),
                'dataset_checksum' => $datasetChecksum,
                'candles_checksum' => $candlesChecksum,
                'quality_report_checksum' => $qualityReportChecksum,
                'source_checksum' => $sourceChecksum,
                'source_network' => $cell->network->value,
                'market_data_venue' => $cell->marketDataVenue->value,
                'market_type' => 'perpetual',
            ];
        } catch (\Throwable $exception) {
            if ($exception instanceof \LogicException
                && $exception->getMessage() === 'paper_canonical_indicator_dataset_invalid'
            ) {
                throw $exception;
            }

            throw new \LogicException('paper_canonical_indicator_dataset_invalid', 0, $exception);
        }
    }

    /**
     * @param list<CanonicalIndicatorCandle> $records
     * @return list<array{market_data_venue:string,market_type:string,symbol:string,timeframe:string,first_open_at:string,last_close_at:string,expected_count:int,observed_count:int,missing_ranges:list<never>}>
     */
    private function streams(array $records): array
    {
        $grouped = [];
        $sourceRecordIds = [];
        $identities = [];
        foreach ($records as $candle) {
            if (isset($sourceRecordIds[$candle->sourceRecordId])) {
                throw new \LogicException('paper_canonical_indicator_dataset_invalid');
            }
            $identity = implode('|', [
                $candle->marketDataVenue,
                $candle->marketType,
                $candle->symbol,
                $candle->timeframe,
                $candle->openAt,
            ]);
            if (isset($identities[$identity])) {
                throw new \LogicException('paper_canonical_indicator_dataset_invalid');
            }
            $sourceRecordIds[$candle->sourceRecordId] = true;
            $identities[$identity] = true;
            $streamKey = implode('|', [
                $candle->marketDataVenue,
                $candle->marketType,
                $candle->symbol,
                $candle->timeframe,
            ]);
            $grouped[$streamKey][] = $candle;
        }

        $streams = [];
        foreach ($grouped as $stream) {
            $previous = null;
            foreach ($stream as $candle) {
                if ($previous !== null && $candle->openAt !== $previous->closeAt) {
                    throw new \LogicException('paper_canonical_indicator_dataset_invalid');
                }
                $previous = $candle;
            }
            $first = $stream[0];
            $last = $stream[array_key_last($stream)];
            $count = count($stream);
            $streams[] = [
                'market_data_venue' => $first->marketDataVenue,
                'market_type' => $first->marketType,
                'symbol' => $first->symbol,
                'timeframe' => $first->timeframe,
                'first_open_at' => $first->openAt,
                'last_close_at' => $last->closeAt,
                'expected_count' => $count,
                'observed_count' => $count,
                'missing_ranges' => [],
            ];
        }

        return $streams;
    }

    private static function compare(CanonicalIndicatorCandle $left, CanonicalIndicatorCandle $right): int
    {
        return [
            $left->marketDataVenue,
            $left->marketType,
            $left->symbol,
            self::DURATIONS[$left->timeframe],
            $left->openAt,
            $left->sourceRecordId,
        ] <=> [
            $right->marketDataVenue,
            $right->marketType,
            $right->symbol,
            self::DURATIONS[$right->timeframe],
            $right->openAt,
            $right->sourceRecordId,
        ];
    }

    private static function checksum(string $payload): string
    {
        return 'sha256:' . hash('sha256', $payload);
    }
}
