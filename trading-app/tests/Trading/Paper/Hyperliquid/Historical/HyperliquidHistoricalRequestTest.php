<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Historical;

use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalRequest;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidHistoricalRequest::class)]
final class HyperliquidHistoricalRequestTest extends TestCase
{
    public function testCanonicalRequestNormalizesSymbolsAndUtcTimestampsWithStableHash(): void
    {
        $first = $this->request(
            symbols: ['ETHUSDT', 'BTCUSDT', 'BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T12:05:00.123456+02:00'),
            to: new \DateTimeImmutable('2026-07-21T11:00:00.654321Z'),
        );
        $second = $this->request(
            symbols: ['BTCUSDT', 'ETHUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:05:00.123456Z'),
            to: new \DateTimeImmutable('2026-07-21T13:00:00.654321+02:00'),
        );

        self::assertSame(['BTCUSDT', 'ETHUSDT'], $first->symbols);
        self::assertSame(['1m', '5m', '15m', '1h'], $first->intervals);
        self::assertSame('2026-07-21T10:05:00.123456Z', $first->from->format('Y-m-d\\TH:i:s.u\\Z'));
        self::assertSame('2026-07-21T11:00:00.654321Z', $first->to->format('Y-m-d\\TH:i:s.u\\Z'));
        self::assertSame($first->requestSha256(), $second->requestSha256());
        self::assertSame([
            'schema_version' => 1,
            'request_sha256' => $first->requestSha256(),
            'from' => '2026-07-21T10:05:00.123456Z',
            'to' => '2026-07-21T11:00:00.654321Z',
            'intervals' => ['1m', '5m', '15m', '1h'],
            'maximum_events' => 1_000_000,
            'maximum_pages' => 100_000,
            'maximum_response_bytes' => 1_048_576,
            'maximum_retries' => 5,
        ], $first->historicalCoverage()->toArray());
    }

    public function testConstructorHasTheRequiredImmutablePublicSurface(): void
    {
        $reflection = new \ReflectionClass(HyperliquidHistoricalRequest::class);
        self::assertTrue($reflection->isReadOnly());
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(
            [
                'datasetid', 'network', 'symbols', 'from', 'to', 'maximumevents', 'maximumpages',
                'maximumresponsebytes', 'maximumretries',
            ],
            array_map(
                static fn (\ReflectionParameter $parameter): string => strtolower($parameter->getName()),
                $constructor->getParameters(),
            ),
        );
    }

    public function testRejectsTheLegacyUnknownNetwork(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_historical_network_invalid');

        $this->request(network: PaperMarketDataNetwork::LEGACY_UNKNOWN);
    }

    /** @return iterable<string, array{array<int, string>}> */
    public static function invalidSymbols(): iterable
    {
        yield 'empty list' => [[]];
        yield 'lowercase' => [['btcusdt']];
        yield 'whitespace' => [[' BTCUSDT']];
        yield 'alias' => [['BTC']];
        yield 'other coin' => [['SOLUSDT']];
    }

    /** @param list<string> $symbols */
    #[DataProvider('invalidSymbols')]
    public function testRejectsInvalidSymbolLists(array $symbols): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_historical_symbols_invalid');

        $this->request(symbols: $symbols);
    }

    public function testRejectsAnEmptyOrInvertedHalfOpenRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_historical_range_invalid');

        $this->request(
            from: new \DateTimeImmutable('2026-07-21T11:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T11:00:00.000000Z'),
        );
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function invalidBounds(): iterable
    {
        yield 'events below minimum' => [0, 1, 1, 0];
        yield 'events above maximum' => [1_000_001, 1, 1, 0];
        yield 'pages below minimum' => [1, 0, 1, 0];
        yield 'pages above maximum' => [1, 100_001, 1, 0];
        yield 'response bytes below minimum' => [1, 1, 0, 0];
        yield 'response bytes above maximum' => [1, 1, 1_048_577, 0];
        yield 'retries below minimum' => [1, 1, 1, -1];
        yield 'retries above maximum' => [1, 1, 1, 6];
    }

    #[DataProvider('invalidBounds')]
    public function testRejectsEveryOutOfBoundsLimit(
        int $maximumEvents,
        int $maximumPages,
        int $maximumResponseBytes,
        int $maximumRetries,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_historical_bound_invalid');

        $this->request(
            maximumEvents: $maximumEvents,
            maximumPages: $maximumPages,
            maximumResponseBytes: $maximumResponseBytes,
            maximumRetries: $maximumRetries,
        );
    }

    public function testRequestSha256PinsTheCanonicalRequestAndIncludesEveryBoundInput(): void
    {
        $base = $this->request();

        self::assertSame(
            'faf0c7be555beeddf13d3c8227f3c1f73175aaeabe3525fd5976006359812440',
            $base->requestSha256(),
        );

        foreach ([
            $this->request(datasetId: 'hyperliquid-history-test-002'),
            $this->request(network: PaperMarketDataNetwork::TESTNET),
            $this->request(symbols: ['ETHUSDT']),
            $this->request(from: new \DateTimeImmutable('2026-07-21T10:00:00.000001Z')),
            $this->request(to: new \DateTimeImmutable('2026-07-21T11:00:00.000001Z')),
            $this->request(maximumEvents: 2),
            $this->request(maximumPages: 2),
            $this->request(maximumResponseBytes: 2),
            $this->request(maximumRetries: 1),
        ] as $changed) {
            self::assertNotSame($base->requestSha256(), $changed->requestSha256());
        }
    }

    /** @param list<string> $symbols */
    private function request(
        PaperMarketDataNetwork $network = PaperMarketDataNetwork::MAINNET,
        array $symbols = ['BTCUSDT'],
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        int $maximumEvents = 1_000_000,
        int $maximumPages = 100_000,
        int $maximumResponseBytes = 1_048_576,
        int $maximumRetries = 5,
        string $datasetId = 'hyperliquid-history-test-001',
    ): HyperliquidHistoricalRequest {
        return new HyperliquidHistoricalRequest(
            datasetId: $datasetId,
            network: $network,
            symbols: $symbols,
            from: $from ?? new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: $to ?? new \DateTimeImmutable('2026-07-21T11:00:00.000000Z'),
            maximumEvents: $maximumEvents,
            maximumPages: $maximumPages,
            maximumResponseBytes: $maximumResponseBytes,
            maximumRetries: $maximumRetries,
        );
    }
}
