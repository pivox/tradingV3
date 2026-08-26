<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRestClientInterface;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCandleWarmup;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidPaperLiveCandleWarmup::class)]
final class HyperliquidPaperLiveCandleWarmupTest extends TestCase
{
    public function testFetchesExactBoundedCanonicalWindows(): void
    {
        $client = new RecordingWarmupRestClient();
        $warmup = new HyperliquidPaperLiveCandleWarmup($client);
        $ends = ['BTC' => '1785297600000', 'ETH' => '1785297600000'];

        $candles = $warmup->candles($ends);

        self::assertCount(3_500, $candles);
        self::assertCount(10, $client->requests);
        foreach (['BTC', 'ETH'] as $coin) {
            foreach (['1m', '5m', '15m'] as $interval) {
                self::assertCount(1, array_filter(
                    $client->requests,
                    static fn (array $request): bool => $request[0] === $coin
                        && $request[1] === $interval,
                ));
            }
            $hourly = array_values(array_filter(
                $client->requests,
                static fn (array $request): bool => $request[0] === $coin
                    && $request[1] === '1h',
            ));
            self::assertCount(2, $hourly);
            self::assertSame($hourly[0][3] + 3_600_000, $hourly[1][2]);
        }
        $btcHourly = array_values(array_filter(
            $candles,
            static fn ($candle): bool => $candle->coin === 'BTC'
                && $candle->interval === '1h',
        ));
        self::assertCount(1_000, $btcHourly);
        self::assertSame(0, $btcHourly[0]->startTime % 14_400_000);
        self::assertSame(1785294000000, $btcHourly[999]->startTime);
    }

    #[DataProvider('invalidPages')]
    public function testRejectsInvalidPages(string $mode): void
    {
        $warmup = new HyperliquidPaperLiveCandleWarmup(
            new RecordingWarmupRestClient($mode),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hyperliquid_paper_public_candle_warmup_invalid');

        $warmup->candles(['BTC' => '1785297600000', 'ETH' => '1785297600000']);
    }

    public function testBackfillsClosedSuffixAfterPinnedObservation(): void
    {
        $client = new RecordingWarmupRestClient();
        $warmup = new HyperliquidPaperLiveCandleWarmup($client);

        $candles = $warmup->candles(
            ['BTC' => '1785297600000', 'ETH' => '1785297600000'],
            1785304800000,
        );

        $btcMinutes = array_values(array_filter(
            $candles,
            static fn ($candle): bool => $candle->coin === 'BTC'
                && $candle->interval === '1m',
        ));
        self::assertCount(370, $btcMinutes);
        self::assertSame(1785304740000, $btcMinutes[array_key_last($btcMinutes)]->startTime);
    }

    public function testCatchupFetchesOnlyTheClosedSuffixAfterDurableFrontiers(): void
    {
        $client = new RecordingWarmupRestClient();
        $warmup = new HyperliquidPaperLiveCandleWarmup($client);

        $candles = $warmup->catchupCandles([
            'BTC/1h' => 1785294000000,
            'BTC/1m' => 1785301140000,
            'BTC/5m' => 1785300900000,
            'BTC/15m' => 1785300300000,
            'ETH/1h' => 1785294000000,
            'ETH/1m' => 1785301140000,
            'ETH/5m' => 1785300900000,
            'ETH/15m' => 1785300300000,
        ], 1785301380000);

        self::assertSame([
            ['BTC', '1m', 1785301200000, 1785301320000],
            ['BTC', '1h', 1785297600000, 1785297600000],
            ['ETH', '1m', 1785301200000, 1785301320000],
            ['ETH', '1h', 1785297600000, 1785297600000],
        ], $client->requests);
        self::assertCount(8, $candles);
        self::assertSame(1785297600000, $candles[0]->startTime);
        self::assertSame(1785301320000, $candles[array_key_last($candles)]->startTime);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPages(): iterable
    {
        yield 'empty' => ['empty'];
        yield 'gap' => ['gap'];
        yield 'duplicate' => ['duplicate'];
        yield 'out of range' => ['out_of_range'];
        yield 'conflict' => ['conflict'];
    }
}

final class RecordingWarmupRestClient implements HyperliquidPaperPublicRestClientInterface
{
    /** @var list<array{string, string, int, int}> */
    public array $requests = [];

    public function __construct(private readonly string $mode = 'valid')
    {
    }

    public function network(): PaperMarketDataNetwork
    {
        return PaperMarketDataNetwork::MAINNET;
    }

    public function candleSnapshot(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes = 1_048_576,
        int $maximumRetries = 5,
    ): array {
        $this->requests[] = [$coin, $interval, $startTime, $endTime];
        $step = ['1m' => 60_000, '5m' => 300_000, '15m' => 900_000, '1h' => 3_600_000][$interval];
        $rows = [];
        for ($start = $startTime; $start <= $endTime; $start += $step) {
            $rows[] = [
                'T' => $start + $step - 1,
                'c' => '100', 'h' => '101', 'i' => $interval,
                'l' => '99', 'n' => 1, 'o' => '100', 's' => $coin,
                't' => $start, 'v' => '10',
            ];
        }

        if (count($this->requests) === 1) {
            if ($this->mode === 'empty') {
                return [];
            }
            if ($this->mode === 'gap') {
                unset($rows[1]);
                $rows = array_values($rows);
            } elseif ($this->mode === 'duplicate') {
                array_splice($rows, 1, 0, [$rows[0]]);
            } elseif ($this->mode === 'out_of_range') {
                $rows[0]['t'] = $startTime - $step;
                $rows[0]['T'] = $startTime - 1;
            } elseif ($this->mode === 'conflict') {
                $conflict = $rows[0];
                $conflict['c'] = '100.5';
                array_splice($rows, 1, 0, [$conflict]);
            }
        }

        return $rows;
    }
}
