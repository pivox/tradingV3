<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveRotationOverlap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidPaperLiveRotationOverlap::class)]
final class HyperliquidPaperLiveRotationOverlapTest extends TestCase
{
    public function testEveryStreamMustBeCoveredAndTheLastEmittedTradeAnchorsTheCut(): void
    {
        $items = [
            self::trades([['BTC', 1, 10], ['BTC', 2, 11], ['BTC', 3, 12]]),
            self::trades([['ETH', 7, 20]]),
            self::book('BTC', 500),
            self::book('ETH', 400),
            ...self::candles(0, 3),
        ];
        $emitted = self::emitted([['BTC', 1, 10], ['BTC', 2, 11], ['ETH', 7, 20]]);
        $state = [
            ['BTC' => 500, 'ETH' => 450],
            self::currentCandles(0, 3),
            [],
        ];

        $evaluation = HyperliquidPaperLiveRotationOverlap::evaluate($items, $emitted, ...$state);

        self::assertSame([], $evaluation['missing']);
        self::assertSame(['BTC' => 1, 'ETH' => 3], $evaluation['trade_cuts']);
        self::assertSame(['BTC' => '11/2', 'ETH' => '20/7'], $evaluation['anchors']);
        $continuation = HyperliquidPaperLiveRotationOverlap::continuation(
            $items,
            $evaluation['trade_cuts'],
            ...$state,
        );
        self::assertSame(1, $continuation['kept']);
        self::assertSame(3 + 2 + 8, $continuation['dropped']);
        self::assertSame(
            [['coin' => 'BTC', 'tid' => 3, 'time' => 12]],
            array_map(
                static fn (array $row): array => ['coin' => $row['coin'], 'tid' => $row['tid'], 'time' => $row['time']],
                $continuation['items'][0]['decoded']['data'],
            ),
        );
    }

    public function testNewerStandbyStatesWaitForTheActiveConnectionAndAreKeptAfterIt(): void
    {
        $items = [
            self::trades([['BTC', 1, 10]]),
            self::trades([['ETH', 7, 20]]),
            self::book('BTC', 600),
            self::book('ETH', 400),
            ...self::candles(0, 4),
        ];
        $emitted = self::emitted([['BTC', 1, 10], ['ETH', 7, 20]]);

        $waiting = HyperliquidPaperLiveRotationOverlap::evaluate(
            $items,
            $emitted,
            ['BTC' => 500, 'ETH' => 400],
            self::currentCandles(0, 3),
            [],
        );

        self::assertSame(
            [
                'book/BTC', 'candle/BTC/1m', 'candle/BTC/5m', 'candle/BTC/15m', 'candle/BTC/1h',
                'candle/ETH/1m', 'candle/ETH/5m', 'candle/ETH/15m', 'candle/ETH/1h',
            ],
            $waiting['missing'],
        );

        $caughtUp = HyperliquidPaperLiveRotationOverlap::evaluate(
            [...$items, self::book('BTC', 700)],
            $emitted,
            ['BTC' => 600, 'ETH' => 400],
            self::currentCandles(0, 4),
            [],
        );
        self::assertSame([], $caughtUp['missing']);
        $continuation = HyperliquidPaperLiveRotationOverlap::continuation(
            [...$items, self::book('BTC', 700)],
            $caughtUp['trade_cuts'],
            ['BTC' => 600, 'ETH' => 400],
            self::currentCandles(0, 3),
            ['BTC/1h' => 0],
        );
        // Candles newer than the active (0, 3), except the finalized BTC/1h start; the 700 book.
        self::assertSame(7 + 1, $continuation['kept']);
    }

    public function testAStandbyTradeConflictingWithAnEmittedIdentityFailsClosed(): void
    {
        $items = [self::trades([['BTC', 1, 10]])];
        $emitted = [self::fingerprint(['BTC', 1, 10])['identity_hash'] => str_repeat('f', 64)];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hyperliquid_paper_natural_identity_conflict');
        HyperliquidPaperLiveRotationOverlap::evaluate($items, $emitted, [], [], []);
    }

    /**
     * @param list<array{string, int, int}> $trades coin, tid, time
     * @return array{decoded: array{kind: string, data: list<array<string, mixed>>}, frame: string, fingerprints: list<array{identity_hash: string, assignment_digest: string}>}
     */
    private static function trades(array $trades): array
    {
        $rows = array_map(
            static fn (array $trade): array => [
                'coin' => $trade[0],
                'tid' => $trade[1],
                'time' => $trade[2],
            ],
            $trades,
        );

        return [
            'decoded' => ['kind' => 'trades', 'data' => $rows],
            'frame' => 'trades',
            'fingerprints' => array_map(self::fingerprint(...), $trades),
        ];
    }

    /**
     * @param array{string, int, int} $trade
     * @return array{identity_hash: string, assignment_digest: string}
     */
    private static function fingerprint(array $trade): array
    {
        return [
            'identity_hash' => hash('sha256', implode('|', $trade)),
            'assignment_digest' => hash('sha256', 'digest|' . implode('|', $trade)),
        ];
    }

    /**
     * @param list<array{string, int, int}> $trades
     * @return array<string, string>
     */
    private static function emitted(array $trades): array
    {
        $emitted = [];
        foreach ($trades as $trade) {
            $fingerprint = self::fingerprint($trade);
            $emitted[$fingerprint['identity_hash']] = $fingerprint['assignment_digest'];
        }

        return $emitted;
    }

    /** @return array{decoded: array{kind: string, data: array<string, mixed>}, frame: string} */
    private static function book(string $coin, int $time): array
    {
        return ['decoded' => ['kind' => 'book', 'data' => ['coin' => $coin, 'time' => $time]], 'frame' => 'book'];
    }

    /** @return list<array{decoded: array{kind: string, data: array<string, mixed>}, frame: string}> */
    private static function candles(int $start, int $trades): array
    {
        $items = [];
        foreach (['BTC', 'ETH'] as $coin) {
            foreach (['1m', '5m', '15m', '1h'] as $interval) {
                $items[] = [
                    'decoded' => [
                        'kind' => 'candle',
                        'data' => ['s' => $coin, 'i' => $interval, 't' => $start, 'n' => $trades],
                    ],
                    'frame' => 'candle',
                ];
            }
        }

        return $items;
    }

    /** @return array<string, array<string, mixed>> */
    private static function currentCandles(int $start, int $trades): array
    {
        $current = [];
        foreach (['BTC', 'ETH'] as $coin) {
            foreach (['1m', '5m', '15m', '1h'] as $interval) {
                $current[$coin . '/' . $interval] = ['t' => $start, 'n' => $trades];
            }
        }

        return $current;
    }
}
