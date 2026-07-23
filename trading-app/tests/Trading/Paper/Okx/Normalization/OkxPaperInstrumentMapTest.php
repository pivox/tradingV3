<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Normalization;

use App\Trading\Paper\Okx\OkxPaperInstrumentMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxPaperInstrumentMap::class)]
final class OkxPaperInstrumentMapTest extends TestCase
{
    public function testMapsOnlyTheApprovedLinearSwapInstrumentsInBothDirections(): void
    {
        $map = new OkxPaperInstrumentMap();

        self::assertSame(['BTC-USDT-SWAP', 'ETH-USDT-SWAP'], $map->nativeInstrumentIds());
        self::assertSame('BTCUSDT', $map->normalizedSymbol('BTC-USDT-SWAP'));
        self::assertSame('ETHUSDT', $map->normalizedSymbol('ETH-USDT-SWAP'));
        self::assertSame('BTC-USDT-SWAP', $map->nativeInstrumentId('BTCUSDT'));
        self::assertSame('ETH-USDT-SWAP', $map->nativeInstrumentId('ETHUSDT'));
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedNativeInstrumentProvider(): iterable
    {
        yield 'other base' => ['SOL-USDT-SWAP'];
        yield 'other quote' => ['BTC-USDC-SWAP'];
        yield 'spot' => ['BTC-USDT'];
        yield 'futures' => ['BTC-USDT-260925'];
        yield 'lowercase' => ['btc-usdt-swap'];
        yield 'normalized symbol' => ['BTCUSDT'];
        yield 'blank' => [''];
    }

    #[DataProvider('rejectedNativeInstrumentProvider')]
    public function testRejectsEveryNativeInstrumentOutsideTheExactMap(string $instrumentId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_instrument_not_allowed');

        (new OkxPaperInstrumentMap())->normalizedSymbol($instrumentId);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedNormalizedSymbolProvider(): iterable
    {
        yield 'other base' => ['SOLUSDT'];
        yield 'other quote' => ['BTCUSDC'];
        yield 'native instrument' => ['BTC-USDT-SWAP'];
        yield 'lowercase' => ['btcusdt'];
        yield 'blank' => [''];
    }

    #[DataProvider('rejectedNormalizedSymbolProvider')]
    public function testRejectsEveryNormalizedSymbolOutsideTheExactMap(string $symbol): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_instrument_not_allowed');

        (new OkxPaperInstrumentMap())->nativeInstrumentId($symbol);
    }
}
