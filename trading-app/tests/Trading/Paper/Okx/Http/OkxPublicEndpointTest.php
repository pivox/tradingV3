<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Http;

use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClientInterface;
use App\Trading\Paper\Okx\Http\OkxPublicEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxPublicEndpoint::class)]
#[CoversClass(OkxPaperPublicRestClientInterface::class)]
final class OkxPublicEndpointTest extends TestCase
{
    public function testEndpointEnumContainsOnlyTheSevenApprovedPublicGetPaths(): void
    {
        self::assertSame(
            [
                '/api/v5/market/history-candles',
                '/api/v5/market/candles',
                '/api/v5/market/history-trades',
                '/api/v5/market/trades',
                '/api/v5/market/books',
                '/api/v5/public/instruments',
                '/api/v5/public/funding-rate',
            ],
            array_map(
                static fn (OkxPublicEndpoint $endpoint): string => $endpoint->value,
                OkxPublicEndpoint::cases(),
            ),
        );

        self::assertSame(300, OkxPublicEndpoint::HistoryCandles->maximumLimit());
        self::assertSame(300, OkxPublicEndpoint::CurrentCandles->maximumLimit());
        self::assertSame(100, OkxPublicEndpoint::HistoryTrades->maximumLimit());
        self::assertSame(500, OkxPublicEndpoint::RecentTrades->maximumLimit());
        self::assertSame(400, OkxPublicEndpoint::OrderBook->maximumLimit());
        self::assertSame(1, OkxPublicEndpoint::Instruments->maximumLimit());
        self::assertSame(1, OkxPublicEndpoint::FundingRate->maximumLimit());
        self::assertTrue(OkxPublicEndpoint::HistoryCandles->usesHistoryRateLimit());
        self::assertTrue(OkxPublicEndpoint::HistoryTrades->usesHistoryRateLimit());
        self::assertFalse(OkxPublicEndpoint::CurrentCandles->usesHistoryRateLimit());
        self::assertFalse(OkxPublicEndpoint::RecentTrades->usesHistoryRateLimit());
        self::assertFalse(OkxPublicEndpoint::OrderBook->usesHistoryRateLimit());
        self::assertFalse(OkxPublicEndpoint::Instruments->usesHistoryRateLimit());
        self::assertFalse(OkxPublicEndpoint::FundingRate->usesHistoryRateLimit());
    }

    public function testRestContractExposesOnlyNamedReadMethods(): void
    {
        $reflection = new \ReflectionClass(OkxPaperPublicRestClientInterface::class);
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        self::assertSame(
            ['currentCandles', 'historyCandles', 'historyTrades', 'orderBook', 'recentTrades'],
            $methods,
        );
        self::assertSame([], array_values(array_filter(
            $methods,
            static fn (string $method): bool => preg_match('/request|post|put|patch|delete|private|generic|publicGet/i', $method) === 1,
        )));
    }
}
