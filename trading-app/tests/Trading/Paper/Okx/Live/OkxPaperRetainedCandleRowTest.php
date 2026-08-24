<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Live;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\Okx\Live\OkxPaperRetainedCandleRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxPaperRetainedCandleRow::class)]
final class OkxPaperRetainedCandleRowTest extends TestCase
{
    public function testCompactsAndExpandsTheExactRestCandleShape(): void
    {
        $row = self::candleRow();
        $compact = CanonicalJson::encode($row);

        self::assertSame($compact, OkxPaperRetainedCandleRow::compact($row));
        self::assertSame($row, OkxPaperRetainedCandleRow::expand($compact));
        self::assertSame($row, OkxPaperRetainedCandleRow::expand($row));
    }

    /** @param array<array-key, mixed>|string $row */
    #[DataProvider('invalidRowProvider')]
    public function testRejectsEveryAmbiguousOrMalformedShape(array|string $row): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_retained_candle_row_invalid');

        OkxPaperRetainedCandleRow::expand($row);
    }

    /** @return iterable<string, array{array<array-key, mixed>|string}> */
    public static function invalidRowProvider(): iterable
    {
        yield 'malformed JSON' => ['["1784970000000"'];
        yield 'short list' => [[
            '1784970000000', '100', '101', '99', '100.5', '10', '1', '1000',
        ]];
        yield 'non-string member' => [[
            1784970000000, '100', '101', '99', '100.5', '10', '1', '1000', '1',
        ]];
        yield 'map shape' => [[
            'timestamp' => '1784970000000',
            'open' => '100',
            'high' => '101',
            'low' => '99',
            'close' => '100.5',
            'volume_contracts' => '10',
            'volume_base' => '1',
            'volume_quote' => '1000',
            'confirmed' => '1',
        ]];
    }

    /** @return list<string> */
    private static function candleRow(): array
    {
        return [
            '1784970000000', '100', '101', '99', '100.5', '10', '1', '1000', '1',
        ];
    }
}
