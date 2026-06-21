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

    public function testFromArrayKeepsCorrelationRunId(): void
    {
        // OBS-003 : run_id de corrélation propagé par l'orchestrateur (X-Run-Id).
        $request = MtfRunnerRequestDto::fromArray(['run_id' => 'run_20260621_abc123']);

        self::assertSame('run_20260621_abc123', $request->runId);
    }

    public function testFromArrayDefaultsRunIdToNull(): void
    {
        self::assertNull(MtfRunnerRequestDto::fromArray([])->runId);
    }

    public function testFromArrayTreatsBlankRunIdAsAbsent(): void
    {
        self::assertNull(MtfRunnerRequestDto::fromArray(['run_id' => '   '])->runId);
        self::assertNull(MtfRunnerRequestDto::fromArray(['run_id' => ['nope']])->runId);
    }

    public function testFromArrayTruncatesRunIdTo64Chars(): void
    {
        // L'orchestration run_id peut atteindre 255 (forme hachée = 68) alors que
        // trade_lifecycle_event.run_id / position_trade_analysis.run_id est VARCHAR(64).
        // On tronque de façon déterministe pour ne pas faire échouer l'INSERT et pour
        // que les deux côtés du rapprochement (OBS-003) utilisent le même identifiant.
        $longRunId = 'run_' . str_repeat('a', 80);
        $request = MtfRunnerRequestDto::fromArray(['run_id' => $longRunId]);

        self::assertSame(64, mb_strlen((string) $request->runId));
        self::assertSame(mb_substr($longRunId, 0, 64), $request->runId);
    }

    public function testFromArrayIgnoresNonArrayOpenStateSnapshot(): void
    {
        self::assertNull(MtfRunnerRequestDto::fromArray(['open_state_snapshot' => 'nope'])->openStateSnapshot);
    }
}
