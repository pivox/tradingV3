<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Live;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\Okx\Live\OkxPaperRetainedTradeRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxPaperRetainedTradeRow::class)]
final class OkxPaperRetainedTradeRowTest extends TestCase
{
    public function testCompactsAndExpandsTheExactRestTradeShape(): void
    {
        $map = self::tradeMap();
        $list = [
            'BTC-USDT-SWAP',
            '42',
            '100.5',
            '2',
            'buy',
            '3',
            '0',
            '1784970100000',
            88001,
        ];
        $compact = CanonicalJson::encode($list);

        self::assertSame($compact, OkxPaperRetainedTradeRow::compact($map));
        self::assertSame($map, OkxPaperRetainedTradeRow::expand($compact));
        self::assertSame($map, OkxPaperRetainedTradeRow::expand($list));
        self::assertSame($map, OkxPaperRetainedTradeRow::expand($map));
    }

    public function testStillExpandsTheLegacyRestTradeShape(): void
    {
        $map = self::legacyTradeMap();
        $list = [
            'BTC-USDT-SWAP',
            '42',
            '100.5',
            '2',
            'buy',
            '0',
            '1784970100000',
        ];

        self::assertSame($map, OkxPaperRetainedTradeRow::expand($list));
        self::assertSame($map, OkxPaperRetainedTradeRow::expand($map));
    }

    /** @param array<array-key, mixed>|string $row */
    #[DataProvider('invalidRowProvider')]
    public function testRejectsEveryAmbiguousOrMalformedShape(array|string $row): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_retained_trade_row_invalid');

        OkxPaperRetainedTradeRow::expand($row);
    }

    /** @return iterable<string, array{array<array-key, mixed>|string}> */
    public static function invalidRowProvider(): iterable
    {
        yield 'malformed JSON' => ['["BTC-USDT-SWAP"'];
        yield 'short list' => [[
            'BTC-USDT-SWAP', '42', '100.5', '2', 'buy', '0',
        ]];
        yield 'non-string list member' => [[
            'BTC-USDT-SWAP', 42, '100.5', '2', 'buy', '0', '1784970100000',
        ]];

        $missing = self::tradeMap();
        unset($missing['source']);
        yield 'missing map key' => [$missing];

        yield 'partial modern map' => [[...self::legacyTradeMap(), 'count' => '1']];
        yield 'invalid modern seqId' => [[...self::tradeMap(), 'seqId' => 1.5]];
        yield 'numeric non-list map' => [[
            1 => 'BTC-USDT-SWAP',
            2 => '42',
            3 => '100.5',
            4 => '2',
            5 => 'buy',
            6 => '0',
            7 => '1784970100000',
        ]];
    }

    /** @return array{instId: string, tradeId: string, px: string, sz: string, side: string, count: string, source: string, ts: string, seqId: int} */
    private static function tradeMap(): array
    {
        return [
            'instId' => 'BTC-USDT-SWAP',
            'tradeId' => '42',
            'px' => '100.5',
            'sz' => '2',
            'side' => 'buy',
            'count' => '3',
            'source' => '0',
            'ts' => '1784970100000',
            'seqId' => 88001,
        ];
    }

    /** @return array<string, string> */
    private static function legacyTradeMap(): array
    {
        return [
            'instId' => 'BTC-USDT-SWAP',
            'tradeId' => '42',
            'px' => '100.5',
            'sz' => '2',
            'side' => 'buy',
            'source' => '0',
            'ts' => '1784970100000',
        ];
    }
}
