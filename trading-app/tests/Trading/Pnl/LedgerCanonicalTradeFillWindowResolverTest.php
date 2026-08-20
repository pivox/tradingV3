<?php

declare(strict_types=1);

namespace App\Tests\Trading\Pnl;

use App\Trading\Pnl\CanonicalTradeFillWindow;
use App\Trading\Pnl\FillQuantityAggregationProviderInterface;
use App\Trading\Pnl\FillQuantityAggregationResult;
use App\Trading\Pnl\LedgerCanonicalTradeFillWindowResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalTradeFillWindow::class)]
#[CoversClass(LedgerCanonicalTradeFillWindowResolver::class)]
final class LedgerCanonicalTradeFillWindowResolverTest extends TestCase
{
    public function testResolvesOnlyACompleteExactFillWindow(): void
    {
        $result = $this->aggregationResult(
            entry: new \DateTimeImmutable('2026-08-20 10:00:01 UTC'),
            exit: new \DateTimeImmutable('2026-08-20 10:04:31 UTC'),
            entryVwap: 101.5,
            complete: true,
        );
        $resolver = new LedgerCanonicalTradeFillWindowResolver($this->provider($result));

        $window = $resolver->resolve('trade-1', 'FAKE', 'PERPETUAL');

        self::assertNotNull($window);
        self::assertSame('2026-08-20T10:00:01+00:00', $window->entryFirstFillAt->format(\DateTimeInterface::ATOM));
        self::assertSame('2026-08-20T10:04:31+00:00', $window->exitLastFillAt->format(\DateTimeInterface::ATOM));
        self::assertSame(101.5, $window->entryVwap);
        self::assertSame(270, $window->holdingTimeSeconds());
    }

    public function testRejectsIncompleteOrChronologicallyInvalidEvidence(): void
    {
        $incomplete = new LedgerCanonicalTradeFillWindowResolver($this->provider($this->aggregationResult(
            entry: new \DateTimeImmutable('2026-08-20 10:00:00 UTC'),
            exit: new \DateTimeImmutable('2026-08-20 10:01:00 UTC'),
            entryVwap: 100.0,
            complete: false,
        )));
        self::assertNull($incomplete->resolve('trade-1', 'fake', 'perpetual'));

        $invalid = new LedgerCanonicalTradeFillWindowResolver($this->provider($this->aggregationResult(
            entry: new \DateTimeImmutable('2026-08-20 10:02:00 UTC'),
            exit: new \DateTimeImmutable('2026-08-20 10:01:00 UTC'),
            entryVwap: 100.0,
            complete: true,
        )));
        self::assertNull($invalid->resolve('trade-1', 'fake', 'perpetual'));

        $lateEntry = new LedgerCanonicalTradeFillWindowResolver($this->provider($this->aggregationResult(
            entry: new \DateTimeImmutable('2026-08-20 10:00:00 UTC'),
            exit: new \DateTimeImmutable('2026-08-20 11:00:00 UTC'),
            entryVwap: 100.0,
            complete: true,
            entryLast: new \DateTimeImmutable('2026-08-20 12:00:00 UTC'),
        )));
        self::assertNull($lateEntry->resolve('trade-1', 'fake', 'perpetual'));
    }

    public function testHoldingTimePreservesSubsecondPrecision(): void
    {
        $window = new CanonicalTradeFillWindow(
            new \DateTimeImmutable('2026-08-20T10:00:00.123456+00:00'),
            new \DateTimeImmutable('2026-08-20T10:03:00.654321+00:00'),
            101.5,
        );

        self::assertEqualsWithDelta(180.530865, $window->holdingTimeSeconds(), 1e-12);
    }

    private function provider(FillQuantityAggregationResult $result): FillQuantityAggregationProviderInterface
    {
        return new class($result) implements FillQuantityAggregationProviderInterface {
            public function __construct(private readonly FillQuantityAggregationResult $result)
            {
            }

            public function aggregateByTradeVenue(string $internalTradeId, string $exchange, string $marketType): FillQuantityAggregationResult
            {
                return $this->result;
            }
        };
    }

    private function aggregationResult(
        \DateTimeImmutable $entry,
        \DateTimeImmutable $exit,
        float $entryVwap,
        bool $complete,
        ?\DateTimeImmutable $entryLast = null,
    ): FillQuantityAggregationResult {
        return new FillQuantityAggregationResult(
            internalTradeId: 'trade-1',
            exchange: 'fake',
            marketType: 'perpetual',
            entryFirstFillAt: $entry,
            entryLastFillAt: $entryLast ?? $entry,
            entryQty: 1.0,
            entryVwap: $entryVwap,
            exitFirstFillAt: $exit,
            exitLastFillAt: $exit,
            exitQty: $complete ? 1.0 : 0.5,
            exitVwap: 102.0,
            remainingQty: $complete ? 0.0 : 0.5,
            positionFullyClosed: $complete,
            quantityStatus: $complete ? 'complete' : 'partial_exit',
            quantityQualityFlags: [],
            feeUsdt: 0.1,
            fundingUsdt: 0.0,
            spreadCostUsdt: 0.0,
            slippageCostUsdt: 0.0,
            borrowCostUsdt: 0.0,
            liquidationFeeUsdt: 0.0,
        );
    }
}
