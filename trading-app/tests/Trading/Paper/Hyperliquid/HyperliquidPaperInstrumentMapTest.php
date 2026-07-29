<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidPaperInstrumentMap::class)]
final class HyperliquidPaperInstrumentMapTest extends TestCase
{
    public function testMapsOnlyApprovedNormalizedSymbolsToNativeCoins(): void
    {
        $map = new HyperliquidPaperInstrumentMap();

        self::assertSame('BTCUSDT', $map->normalizedSymbol('BTC'));
        self::assertSame('ETHUSDT', $map->normalizedSymbol('ETH'));
        self::assertSame('BTC', $map->nativeCoin('BTCUSDT'));
        self::assertSame('ETH', $map->nativeCoin('ETHUSDT'));
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedSymbols(): iterable
    {
        yield 'lowercase' => ['btcusdt'];
        yield 'leading whitespace' => [' BTCUSDT'];
        yield 'trailing whitespace' => ['BTCUSDT '];
        yield 'native coin alias' => ['BTC'];
        yield 'dash alias' => ['BTC-USDT'];
        yield 'other coin' => ['SOLUSDT'];
        yield 'other quote' => ['BTCUSDC'];
        yield 'blank' => [''];
    }

    #[DataProvider('rejectedSymbols')]
    public function testRejectsEveryNormalizedSymbolOutsideTheExactMap(string $symbol): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_paper_symbol_invalid');

        (new HyperliquidPaperInstrumentMap())->nativeCoin($symbol);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedNativeCoins(): iterable
    {
        yield 'lowercase' => ['btc'];
        yield 'whitespace' => [' BTC'];
        yield 'normalized symbol alias' => ['BTCUSDT'];
        yield 'other coin' => ['SOL'];
        yield 'blank' => [''];
    }

    #[DataProvider('rejectedNativeCoins')]
    public function testRejectsEveryNativeCoinOutsideTheExactMap(string $coin): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_paper_symbol_invalid');

        (new HyperliquidPaperInstrumentMap())->normalizedSymbol($coin);
    }

    public function testMapsOnlyTheFixedPublicIntervalsToMilliseconds(): void
    {
        $map = new HyperliquidPaperInstrumentMap();

        self::assertSame(60_000, $map->intervalMilliseconds('1m'));
        self::assertSame(300_000, $map->intervalMilliseconds('5m'));
        self::assertSame(900_000, $map->intervalMilliseconds('15m'));
        self::assertSame(3_600_000, $map->intervalMilliseconds('1h'));
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedIntervals(): iterable
    {
        yield 'uppercase hour' => ['1H'];
        yield 'lowercase minute' => ['1M'];
        yield 'whitespace' => [' 1m'];
        yield 'unsupported minute' => ['3m'];
        yield 'unsupported hour' => ['4h'];
        yield 'blank' => [''];
    }

    #[DataProvider('rejectedIntervals')]
    public function testRejectsEveryIntervalOutsideTheExactMap(string $interval): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_paper_interval_invalid');

        (new HyperliquidPaperInstrumentMap())->intervalMilliseconds($interval);
    }
}
