<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Historical;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\PaperHistoricalDatasetBuilder;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\Okx\Historical\OkxHistoricalEventStream;
use App\Trading\Paper\Okx\Historical\OkxHistoricalRequest;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(OkxHistoricalEventStream::class)]
#[CoversClass(PaperHistoricalDatasetBuilder::class)]
final class OkxHistoricalEventStreamTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'okx-history-test-');
        if ($path === false || !unlink($path) || !mkdir($path, 0700)) {
            self::fail('Unable to create test directory.');
        }
        $resolved = realpath($path);
        self::assertIsString($resolved);
        $this->testRoot = $resolved;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testRoot);
    }

    public function testReversedPagesProduceChronologicalBoundedDatasetWithoutLosingSameMillisecondTrades(): void
    {
        $request = $this->request('okx-history-happy-001');
        $client = ScriptedHistoricalRestClient::completeRange($request, candlePageSize: 7);
        $recorder = $this->recorder($request);
        $source = new OkxHistoricalEventStream(
            restClient: $client,
            clock: new MockClock('2026-07-21T12:00:00.000000Z'),
            request: $request,
            datasetDirectory: $recorder->datasetDirectory(),
        );

        $manifest = (new PaperHistoricalDatasetBuilder())->build($recorder, $source);

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        $events = $this->events($recorder->datasetDirectory());
        self::assertCount(283, $events);
        $sortKeys = array_map(
            static fn (array $event): string => implode('|', [
                $event['exchange_timestamp'],
                $event['channel'],
                $event['symbol'],
                (string) ($event['payload']['trade_id'] ?? $event['payload']['bar']),
            ]),
            $events,
        );
        $sortedKeys = $sortKeys;
        sort($sortedKeys, \SORT_STRING);
        self::assertSame($sortedKeys, $sortKeys);

        $trades = array_values(array_filter(
            $events,
            static fn (array $event): bool => $event['channel'] === 'public_trade',
        ));
        self::assertCount(206, $trades);
        self::assertCount(206, array_unique(array_column(array_column($trades, 'payload'), 'trade_id')));
        self::assertCount(205, array_filter(
            $trades,
            static fn (array $event): bool => $event['exchange_timestamp'] === '2026-07-21T10:30:00.123000Z',
        ));
        self::assertContains('2026-07-21T10:00:00.000000Z', array_column($trades, 'exchange_timestamp'));
        self::assertNotContains('2026-07-21T11:00:00.000000Z', array_column($trades, 'exchange_timestamp'));

        $tradeCalls = array_values(array_filter(
            $client->calls,
            static fn (array $call): bool => $call['method'] === 'historyTrades',
        ));
        self::assertSame(2, $tradeCalls[0]['pagination_type']);
        self::assertSame('1784631600000', $tradeCalls[0]['after']);
        self::assertSame(1, $tradeCalls[1]['pagination_type']);
        self::assertSame('1106', $tradeCalls[1]['after']);

        $candleCalls = array_values(array_filter(
            $client->calls,
            static fn (array $call): bool => $call['method'] === 'historyCandles'
                && $call['bar'] === '1m',
        ));
        self::assertGreaterThan(2, \count($candleCalls));
        self::assertSame('1784631600000', $candleCalls[0]['after']);
        self::assertLessThan((int) $candleCalls[0]['after'], (int) $candleCalls[1]['after']);

        $checkpointDirectory = $recorder->datasetDirectory() . '/checkpoints/okx-acquisition';
        self::assertSame(0700, fileperms($checkpointDirectory) & 0777);
        self::assertSame(0700, fileperms($checkpointDirectory . '/pages') & 0777);
        self::assertSame(0600, fileperms($checkpointDirectory . '/checkpoint.json') & 0777);
        foreach (glob($checkpointDirectory . '/pages/*.ndjson') ?: [] as $page) {
            self::assertSame(0600, fileperms($page) & 0777);
        }
    }

    private function request(string $datasetId): OkxHistoricalRequest
    {
        return new OkxHistoricalRequest(
            datasetId: $datasetId,
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T11:00:00.000000Z'),
        );
    }

    private function recorder(OkxHistoricalRequest $request): PaperDatasetRecorder
    {
        return new PaperDatasetRecorder($this->testRoot, new PaperDatasetManifest(
            schemaVersion: 1,
            recorderVersion: '1.0.0',
            datasetId: $request->datasetId,
            venue: PaperMarketDataVenue::OKX,
            symbols: ['BTCUSDT' => 'BTC-USDT-SWAP'],
            startExchangeTimestamp: null,
            endExchangeTimestamp: null,
            channels: [],
            eventCount: 0,
            sequenceGaps: [],
            quality: PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_AND_TRADES,
            modelName: 'historical_top_of_book_unavailable',
            modelVersion: '1',
            eventsFileSha256: null,
            state: PaperDatasetState::RECORDING,
            lastEventId: null,
        ));
    }

    /** @return list<array<string, mixed>> */
    private function events(string $datasetDirectory): array
    {
        $lines = file($datasetDirectory . '/events.ndjson', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        return array_map(static function (string $line): array {
            $event = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($event);

            return $event;
        }, $lines);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}

final class ScriptedHistoricalRestClient implements OkxPaperPublicRestClientInterface
{
    /** @var list<array<string, int|string|null>> */
    public array $calls = [];

    /** @param array<string, list<array<array-key, mixed>>> $candles */
    private function __construct(
        private readonly OkxHistoricalRequest $request,
        private readonly array $candles,
        /** @var list<array<string, mixed>> */
        private readonly array $trades,
        private readonly int $candlePageSize,
    ) {
    }

    public static function completeRange(
        OkxHistoricalRequest $request,
        int $candlePageSize = 300,
    ): self {
        $candles = [];
        foreach (['1m' => 60_000, '5m' => 300_000, '15m' => 900_000, '1H' => 3_600_000] as $bar => $step) {
            $rows = [];
            $from = ((int) $request->from->format('U')) * 1_000;
            $to = ((int) $request->to->format('U')) * 1_000;
            $first = intdiv($from + $step - 1, $step) * $step;
            for ($timestamp = $first; $timestamp < $to; $timestamp += $step) {
                $rows[] = self::candle((string) $timestamp);
            }
            $rows[] = self::candle((string) ($first - $step));
            usort($rows, static fn (array $left, array $right): int => (int) $right[0] <=> (int) $left[0]);
            $candles[$bar] = $rows;
        }

        $trades = [];
        for ($tradeId = 1_205; $tradeId >= 1_001; --$tradeId) {
            $trades[] = self::trade((string) $tradeId, '1784629800123');
        }
        $trades[] = self::trade('1000', '1784628000000');
        $trades[] = self::trade('999', '1784627999999');
        $trades[] = self::trade('1206', '1784631600000');
        usort($trades, static function (array $left, array $right): int {
            $byTimestamp = (int) $right['ts'] <=> (int) $left['ts'];

            return $byTimestamp !== 0 ? $byTimestamp : (int) $right['tradeId'] <=> (int) $left['tradeId'];
        });

        return new self($request, $candles, $trades, $candlePageSize);
    }

    public function historyCandles(
        string $instrumentId,
        string $bar,
        ?string $after = null,
        int $limit = 300,
    ): array {
        $this->calls[] = compact('instrumentId', 'bar', 'after') + ['method' => 'historyCandles'];
        $rows = array_values(array_filter(
            $this->candles[$bar] ?? [],
            static fn (array $row): bool => $after === null || (int) $row[0] < (int) $after,
        ));

        return array_slice($rows, 0, min($limit, $this->candlePageSize));
    }

    public function historyTrades(
        string $instrumentId,
        int $paginationType = 2,
        ?string $after = null,
        int $limit = 100,
    ): array {
        $this->calls[] = [
            'method' => 'historyTrades',
            'instrumentId' => $instrumentId,
            'pagination_type' => $paginationType,
            'after' => $after,
        ];
        $rows = array_values(array_filter(
            $this->trades,
            static fn (array $row): bool => $after === null || ($paginationType === 2
                ? (int) $row['ts'] < (int) $after
                : (int) $row['tradeId'] < (int) $after),
        ));

        return array_slice($rows, 0, $limit);
    }

    public function currentCandles(
        string $instrumentId,
        string $bar,
        ?string $after = null,
        ?string $before = null,
        int $limit = 300,
    ): array {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function recentTrades(string $instrumentId, int $limit = 500): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    public function orderBook(string $instrumentId, int $depth = 400): array
    {
        throw new \LogicException('unexpected_non_historical_call');
    }

    /** @return list<string> */
    public function tradeIds(): array
    {
        return array_column($this->trades, 'tradeId');
    }

    /** @return list<string> */
    public function bars(): array
    {
        return $this->request->bars;
    }

    /** @return list<string> */
    private static function candle(string $timestamp): array
    {
        return [$timestamp, '1.0', '2.0', '0.5', '1.5', '10', '10.0', '15.0', '1'];
    }

    /** @return array<string, string> */
    private static function trade(string $tradeId, string $timestamp): array
    {
        return [
            'instId' => 'BTC-USDT-SWAP',
            'tradeId' => $tradeId,
            'px' => '65000.1',
            'sz' => '1',
            'side' => 'buy',
            'source' => '0',
            'ts' => $timestamp,
        ];
    }
}
