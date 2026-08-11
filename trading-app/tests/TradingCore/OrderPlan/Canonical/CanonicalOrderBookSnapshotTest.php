<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan\Canonical;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalOrderBookSnapshot::class)]
final class CanonicalOrderBookSnapshotTest extends TestCase
{
    public function testCanonicalSnapshotCarriesAnImmutableDerivedSpreadAuthority(): void
    {
        $snapshot = self::snapshot();

        self::assertSame('fake', $snapshot->exchange);
        self::assertSame('test', $snapshot->environment);
        self::assertSame('BTCUSDT', $snapshot->symbol);
        self::assertSame('perpetual', $snapshot->marketType);
        self::assertSame('order_book', $snapshot->source);
        self::assertSame(99.995, $snapshot->bestBid);
        self::assertSame(100.005, $snapshot->bestAsk);
        self::assertSame(1.0, $snapshot->spreadBps);
        self::assertEqualsWithDelta(1.0, $snapshot->derivedSpreadBps(), 1.0e-9);
        self::assertSame('2026-08-10T11:59:45+00:00', $snapshot->observedAt->format(DATE_ATOM));
        self::assertSame('sha256:' . str_repeat('7', 64), $snapshot->inputHash);

        try {
            (new \ReflectionProperty($snapshot, 'bestBid'))->setValue($snapshot, 1.0);
            self::fail('Readonly order book was mutated.');
        } catch (\Error) {
            self::assertSame(99.995, $snapshot->bestBid);
        }
    }

    #[DataProvider('invalidBooks')]
    public function testInvalidPricesAndSpreadAreRejectedAtTheCanonicalBoundary(
        float $bestBid,
        float $bestAsk,
        float $spreadBps,
        string $reason,
    ): void {
        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage($reason);

        self::snapshot($bestBid, $bestAsk, $spreadBps);
    }

    /** @return iterable<string, array{float, float, float, string}> */
    public static function invalidBooks(): iterable
    {
        yield 'zero bid' => [0.0, 100.005, 1.0, 'canonical_order_book_price_invalid'];
        yield 'negative ask' => [99.995, -100.005, 1.0, 'canonical_order_book_price_invalid'];
        yield 'non finite bid' => [NAN, 100.005, 1.0, 'canonical_order_book_price_invalid'];
        yield 'non finite ask' => [99.995, INF, 1.0, 'canonical_order_book_price_invalid'];
        yield 'equal book' => [100.0, 100.0, 0.0, 'canonical_order_book_crossed'];
        yield 'crossed book' => [100.1, 100.0, 0.0, 'canonical_order_book_crossed'];
        yield 'non finite spread' => [99.995, 100.005, NAN, 'canonical_order_book_spread_invalid'];
        yield 'negative spread' => [99.995, 100.005, -1.0, 'canonical_order_book_spread_invalid'];
        yield 'spread does not match levels' => [99.995, 100.005, 2.0, 'canonical_order_book_spread_mismatch'];
    }

    private static function snapshot(
        float $bestBid = 99.995,
        float $bestAsk = 100.005,
        float $spreadBps = 1.0,
    ): CanonicalOrderBookSnapshot {
        return new CanonicalOrderBookSnapshot(
            'fake',
            'test',
            'BTCUSDT',
            'perpetual',
            'order_book',
            $bestBid,
            $bestAsk,
            $spreadBps,
            new \DateTimeImmutable('2026-08-10T11:59:45+00:00'),
            'sha256:' . str_repeat('7', 64),
        );
    }
}
