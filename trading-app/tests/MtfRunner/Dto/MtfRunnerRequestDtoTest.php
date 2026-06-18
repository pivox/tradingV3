<?php

declare(strict_types=1);

namespace App\Tests\MtfRunner\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\MtfRunner\Dto\MtfRunnerRequestDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MtfRunnerRequestDto::class)]
final class MtfRunnerRequestDtoTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: Exchange}>
     */
    public static function exchangeProvider(): iterable
    {
        yield 'bitmart' => ['bitmart', Exchange::BITMART];
        yield 'binance' => ['binance', Exchange::BINANCE];
        yield 'fake' => ['fake', Exchange::FAKE];
        yield 'okx' => ['okx', Exchange::OKX];
        yield 'hyperliquid' => ['hyperliquid', Exchange::HYPERLIQUID];
        yield 'trimmed uppercase okx' => [' OKX ', Exchange::OKX];
    }

    #[DataProvider('exchangeProvider')]
    public function testFromArrayNormalizesAllExchangeEnumValues(string $input, Exchange $expected): void
    {
        $request = MtfRunnerRequestDto::fromArray(['exchange' => $input]);

        self::assertSame($expected, $request->exchange);
    }

    public function testFromArrayRejectsUnknownExchange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported exchange "unknown"');

        MtfRunnerRequestDto::fromArray(['exchange' => 'unknown']);
    }

    /**
     * @return iterable<string, array{0: string, 1: MarketType}>
     */
    public static function marketTypeProvider(): iterable
    {
        yield 'perpetual' => ['perpetual', MarketType::PERPETUAL];
        yield 'futures alias' => ['futures', MarketType::PERPETUAL];
        yield 'future alias' => ['future', MarketType::PERPETUAL];
        yield 'perp alias' => ['perp', MarketType::PERPETUAL];
        yield 'spot' => ['spot', MarketType::SPOT];
    }

    #[DataProvider('marketTypeProvider')]
    public function testFromArrayNormalizesMarketTypeAliases(string $input, MarketType $expected): void
    {
        $request = MtfRunnerRequestDto::fromArray(['market_type' => $input]);

        self::assertSame($expected, $request->marketType);
    }

    public function testFromArrayParsesOpenStateSnapshot(): void
    {
        $request = MtfRunnerRequestDto::fromArray([
            'open_state_snapshot' => [
                'open_positions' => [['symbol' => 'BTCUSDT']],
                'open_orders' => [['symbol' => 'ETHUSDT']],
            ],
        ]);

        self::assertNotNull($request->openStateSnapshot);
        self::assertSame('BTCUSDT', $request->openStateSnapshot['open_positions'][0]['symbol']);
        self::assertSame('ETHUSDT', $request->openStateSnapshot['open_orders'][0]['symbol']);
    }

    public function testFromArrayDefaultsOpenStateSnapshotToNull(): void
    {
        self::assertNull(MtfRunnerRequestDto::fromArray([])->openStateSnapshot);
    }

    public function testFromArrayKeepsWellFormedEmptyOpenStateSnapshot(): void
    {
        // Snapshot vide mais bien formé : l'orchestrateur a interrogé l'exchange,
        // rien n'était ouvert. Reste une source fiable (les deux clés sont des tableaux).
        $request = MtfRunnerRequestDto::fromArray([
            'open_state_snapshot' => ['open_positions' => [], 'open_orders' => []],
        ]);

        self::assertNotNull($request->openStateSnapshot);
        self::assertSame([], $request->openStateSnapshot['open_positions']);
        self::assertSame([], $request->openStateSnapshot['open_orders']);
    }

    public function testFromArrayRejectsPartialOpenStateSnapshot(): void
    {
        // Clé open_orders manquante => snapshot mal formé => null, pour que le garde
        // fail-closed en live ne soit pas contourné par un payload incomplet.
        self::assertNull(MtfRunnerRequestDto::fromArray([
            'open_state_snapshot' => ['open_positions' => [['symbol' => 'BTCUSDT']]],
        ])->openStateSnapshot);
    }

    public function testFromArrayRejectsEmptyObjectOpenStateSnapshot(): void
    {
        self::assertNull(MtfRunnerRequestDto::fromArray(['open_state_snapshot' => []])->openStateSnapshot);
    }

    public function testFromArrayRejectsNonArraySnapshotKeys(): void
    {
        self::assertNull(MtfRunnerRequestDto::fromArray([
            'open_state_snapshot' => ['open_positions' => 'nope', 'open_orders' => []],
        ])->openStateSnapshot);
    }

    public function testFromArrayIgnoresNonArrayOpenStateSnapshot(): void
    {
        self::assertNull(MtfRunnerRequestDto::fromArray(['open_state_snapshot' => 'nope'])->openStateSnapshot);
    }
}
