<?php

declare(strict_types=1);

namespace App\Tests\Trading\Pnl;

use App\Entity\FillCostLedgerEntry;
use App\Trading\Pnl\FillQuantityAggregationResult;
use App\Trading\Pnl\FillQuantityAggregationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FillQuantityAggregationService::class)]
#[CoversClass(FillQuantityAggregationResult::class)]
final class FillQuantityAggregationServiceTest extends TestCase
{
    public function testAggregatesPartialEntryAndTp1TrailingExitAsClosedTrade(): void
    {
        $service = new FillQuantityAggregationService();

        $result = $service->aggregateEntries([
            self::fill('entry-a', 'entry', '2026-06-25 10:00:00 UTC', 100.0, 0.4, exchange: 'bitmart', marketType: 'futures', feeUsdt: 0.02),
            self::fill('entry-b', 'entry', '2026-06-25 10:00:20 UTC', 101.0, 0.6, exchange: 'bitmart', marketType: 'futures', feeUsdt: 0.03),
            self::fill('tp1', 'exit', '2026-06-25 10:10:00 UTC', 110.0, 0.5, exchange: 'bitmart', marketType: 'futures', feeUsdt: 0.04),
            self::fill('trailing', 'exit', '2026-06-25 10:20:00 UTC', 108.0, 0.5, exchange: 'bitmart', marketType: 'futures', feeUsdt: 0.05),
            self::fill('funding-credit', 'funding', '2026-06-25 10:15:00 UTC', null, null, exchange: 'bitmart', marketType: 'futures', fundingUsdt: 0.12),
            self::fill('spread', 'adjustment', '2026-06-25 10:20:01 UTC', null, null, exchange: 'bitmart', marketType: 'futures', spreadCostUsdt: 0.07),
        ], internalTradeId: 'shared-trade-id', exchange: 'bitmart', marketType: 'futures');

        self::assertSame('shared-trade-id', $result->internalTradeId);
        self::assertSame('bitmart', $result->exchange);
        self::assertSame('futures', $result->marketType);
        self::assertEquals(new \DateTimeImmutable('2026-06-25 10:00:00 UTC'), $result->entryFirstFillAt);
        self::assertEquals(new \DateTimeImmutable('2026-06-25 10:00:20 UTC'), $result->entryLastFillAt);
        self::assertEqualsWithDelta(1.0, $result->entryQty, 1e-12);
        self::assertEqualsWithDelta(100.6, $result->entryVwap, 1e-12);
        self::assertEquals(new \DateTimeImmutable('2026-06-25 10:10:00 UTC'), $result->exitFirstFillAt);
        self::assertEquals(new \DateTimeImmutable('2026-06-25 10:20:00 UTC'), $result->exitLastFillAt);
        self::assertEqualsWithDelta(1.0, $result->exitQty, 1e-12);
        self::assertEqualsWithDelta(109.0, $result->exitVwap, 1e-12);
        self::assertEqualsWithDelta(0.0, $result->remainingQty, 1e-12);
        self::assertTrue($result->positionFullyClosed);
        self::assertSame('complete', $result->quantityStatus);
        self::assertSame([], $result->quantityQualityFlags);
        self::assertEqualsWithDelta(0.14, $result->feeUsdt, 1e-12);
        self::assertEqualsWithDelta(0.12, $result->fundingUsdt, 1e-12);
        self::assertEqualsWithDelta(0.07, $result->spreadCostUsdt, 1e-12);
        self::assertTrue($result->netPnlCertificationAllowed());
    }

    public function testPartialExitKeepsTradeOpenAndBlocksNetPnlCertification(): void
    {
        $service = new FillQuantityAggregationService();

        $result = $service->aggregateEntries([
            self::fill('entry', 'entry', '2026-06-25 10:00:00 UTC', 100.0, 2.0),
            self::fill('tp1', 'exit', '2026-06-25 10:05:00 UTC', 105.0, 0.8),
        ], internalTradeId: 'shared-trade-id', exchange: 'fake', marketType: 'paper');

        self::assertEqualsWithDelta(2.0, $result->entryQty, 1e-12);
        self::assertEqualsWithDelta(0.8, $result->exitQty, 1e-12);
        self::assertEqualsWithDelta(1.2, $result->remainingQty, 1e-12);
        self::assertFalse($result->positionFullyClosed);
        self::assertSame('open_position', $result->quantityStatus);
        self::assertContains('position_not_fully_closed', $result->quantityQualityFlags);
        self::assertFalse($result->netPnlCertificationAllowed());
    }

    public function testOneWaySingleEntrySingleExitIsComplete(): void
    {
        $service = new FillQuantityAggregationService();

        $result = $service->aggregateEntries([
            self::fill('entry', 'entry', '2026-06-25 10:00:00 UTC', 100.0, 1.0, side: 'BUY'),
            self::fill('exit', 'exit', '2026-06-25 10:05:00 UTC', 103.0, 1.0, side: 'SELL'),
        ], internalTradeId: 'shared-trade-id', exchange: 'fake', marketType: 'paper');

        self::assertEqualsWithDelta(1.0, $result->entryQty, 1e-12);
        self::assertEqualsWithDelta(1.0, $result->exitQty, 1e-12);
        self::assertEqualsWithDelta(0.0, $result->remainingQty, 1e-12);
        self::assertTrue($result->positionFullyClosed);
        self::assertSame('complete', $result->quantityStatus);
    }

    public function testHedgeShortPartialStopUsesFillRolesAndKeepsResidualOpen(): void
    {
        $service = new FillQuantityAggregationService();

        $result = $service->aggregateEntries([
            self::fill('short-entry', 'entry', '2026-06-25 10:00:00 UTC', 100.0, 1.0, side: 'SELL'),
            self::fill('partial-stop', 'exit', '2026-06-25 10:03:00 UTC', 102.0, 0.4, side: 'BUY'),
        ], internalTradeId: 'shared-trade-id', exchange: 'fake', marketType: 'paper');

        self::assertEqualsWithDelta(1.0, $result->entryQty, 1e-12);
        self::assertEqualsWithDelta(0.4, $result->exitQty, 1e-12);
        self::assertEqualsWithDelta(0.6, $result->remainingQty, 1e-12);
        self::assertFalse($result->positionFullyClosed);
        self::assertSame('open_position', $result->quantityStatus);
        self::assertContains('position_not_fully_closed', $result->quantityQualityFlags);
    }

    public function testOverExitIsQuantityMismatchAndNeverClosed(): void
    {
        $service = new FillQuantityAggregationService();

        $result = $service->aggregateEntries([
            self::fill('entry', 'entry', '2026-06-25 10:00:00 UTC', 100.0, 1.0),
            self::fill('exit-a', 'exit', '2026-06-25 10:05:00 UTC', 101.0, 0.7),
            self::fill('exit-b', 'exit', '2026-06-25 10:06:00 UTC', 102.0, 0.5),
        ], internalTradeId: 'shared-trade-id', exchange: 'fake', marketType: 'paper');

        self::assertEqualsWithDelta(-0.2, $result->remainingQty, 1e-12);
        self::assertFalse($result->positionFullyClosed);
        self::assertSame('quantity_mismatch', $result->quantityStatus);
        self::assertContains('exit_qty_exceeds_entry_qty', $result->quantityQualityFlags);
        self::assertFalse($result->netPnlCertificationAllowed());
    }

    public function testDuplicateExactFillDoesNotChangeAggregateButConflictingDuplicateIsFlagged(): void
    {
        $service = new FillQuantityAggregationService();

        $exactDuplicate = [
            self::fill('entry', 'entry', '2026-06-25 10:00:00 UTC', 100.0, 1.0, exchangeFillId: 'venue-fill-1'),
            self::fill('entry-replay', 'entry', '2026-06-25 10:00:00 UTC', 100.0, 1.0, exchangeFillId: 'venue-fill-1'),
            self::fill('exit', 'exit', '2026-06-25 10:05:00 UTC', 101.0, 1.0, exchangeFillId: 'venue-fill-2'),
        ];

        $replayResult = $service->aggregateEntries($exactDuplicate, internalTradeId: 'shared-trade-id', exchange: 'fake', marketType: 'paper');

        self::assertEqualsWithDelta(1.0, $replayResult->entryQty, 1e-12);
        self::assertSame('complete', $replayResult->quantityStatus);
        self::assertContains('duplicate_fill_ignored', $replayResult->quantityQualityFlags);
        self::assertTrue($replayResult->netPnlCertificationAllowed());

        $conflictingDuplicate = [
            self::fill('entry', 'entry', '2026-06-25 10:00:00 UTC', 100.0, 1.0, exchangeFillId: 'venue-fill-1'),
            self::fill('entry-conflict', 'entry', '2026-06-25 10:00:00 UTC', 101.0, 1.0, exchangeFillId: 'venue-fill-1'),
            self::fill('exit', 'exit', '2026-06-25 10:05:00 UTC', 102.0, 1.0, exchangeFillId: 'venue-fill-2'),
        ];

        $conflictResult = $service->aggregateEntries($conflictingDuplicate, internalTradeId: 'shared-trade-id', exchange: 'fake', marketType: 'paper');

        self::assertSame('fill_conflict', $conflictResult->quantityStatus);
        self::assertContains('fill_conflict', $conflictResult->quantityQualityFlags);
        self::assertFalse($conflictResult->netPnlCertificationAllowed());
    }

    public function testCancelledOrCorrectedFillIsIgnoredAuditably(): void
    {
        $service = new FillQuantityAggregationService();

        $result = $service->aggregateEntries([
            self::fill('entry-cancelled', 'entry', '2026-06-25 09:59:00 UTC', 99.0, 1.0, qualityFlags: ['fill_cancelled']),
            self::fill('entry', 'entry', '2026-06-25 10:00:00 UTC', 100.0, 1.0),
            self::fill('exit', 'exit', '2026-06-25 10:05:00 UTC', 101.0, 1.0),
        ], internalTradeId: 'shared-trade-id', exchange: 'fake', marketType: 'paper');

        self::assertEqualsWithDelta(1.0, $result->entryQty, 1e-12);
        self::assertEqualsWithDelta(100.0, $result->entryVwap, 1e-12);
        self::assertSame('complete', $result->quantityStatus);
        self::assertContains('cancelled_fill_ignored', $result->quantityQualityFlags);
    }

    public function testSameInternalTradeIdOnDifferentVenuesStaysSeparated(): void
    {
        $service = new FillQuantityAggregationService();

        $fills = [
            self::fill('bitmart-entry', 'entry', '2026-06-25 10:00:00 UTC', 100.0, 1.0, exchange: 'bitmart', marketType: 'futures'),
            self::fill('bitmart-exit', 'exit', '2026-06-25 10:05:00 UTC', 101.0, 1.0, exchange: 'bitmart', marketType: 'futures'),
            self::fill('fake-entry', 'entry', '2026-06-25 10:00:00 UTC', 200.0, 2.0, exchange: 'fake', marketType: 'paper'),
            self::fill('fake-exit', 'exit', '2026-06-25 10:05:00 UTC', 201.0, 1.0, exchange: 'fake', marketType: 'paper'),
        ];

        $bitmart = $service->aggregateEntries($fills, internalTradeId: 'shared-trade-id', exchange: 'bitmart', marketType: 'futures');
        $fake = $service->aggregateEntries($fills, internalTradeId: 'shared-trade-id', exchange: 'fake', marketType: 'paper');

        self::assertEqualsWithDelta(0.0, $bitmart->remainingQty, 1e-12);
        self::assertSame('complete', $bitmart->quantityStatus);
        self::assertEqualsWithDelta(1.0, $fake->remainingQty, 1e-12);
        self::assertSame('open_position', $fake->quantityStatus);
    }

    public function testOrderExpiredWithoutFillProducesMissingEntryStatus(): void
    {
        $service = new FillQuantityAggregationService();

        $result = $service->aggregateEntries([], internalTradeId: 'shared-trade-id', exchange: 'fake', marketType: 'paper');

        self::assertNull($result->entryQty);
        self::assertNull($result->exitQty);
        self::assertNull($result->remainingQty);
        self::assertFalse($result->positionFullyClosed);
        self::assertSame('missing_entry_fill', $result->quantityStatus);
        self::assertContains('missing_entry_fill', $result->quantityQualityFlags);
        self::assertFalse($result->netPnlCertificationAllowed());
    }

    /**
     * @param list<string> $qualityFlags
     */
    private static function fill(
        string $fillId,
        string $role,
        string $occurredAt,
        ?float $price,
        ?float $quantity,
        string $exchange = 'fake',
        string $marketType = 'paper',
        ?float $feeUsdt = null,
        ?float $fundingUsdt = null,
        ?float $spreadCostUsdt = null,
        ?string $exchangeFillId = null,
        array $qualityFlags = [],
        ?string $side = null,
    ): FillCostLedgerEntry {
        $entry = new FillCostLedgerEntry(
            idempotencyKey: sprintf('%s:%s:%s', $exchange, $marketType, $fillId),
            payloadHash: hash('sha256', $fillId),
            exchange: $exchange,
            marketType: $marketType,
            symbol: 'BTCUSDT',
            fillId: $fillId,
            fillRole: $role,
            occurredAt: new \DateTimeImmutable($occurredAt),
            source: 'test',
            sourceVersion: 'test_v1',
        );

        return $entry
            ->setInternalTradeId('shared-trade-id')
            ->setPrice($price !== null ? sprintf('%.12F', $price) : null)
            ->setQuantity($quantity !== null ? sprintf('%.12F', $quantity) : null)
            ->setNotional($price !== null && $quantity !== null ? sprintf('%.12F', $price * $quantity) : null)
            ->setFeeUsdt($feeUsdt !== null ? sprintf('%.12F', $feeUsdt) : null)
            ->setFundingUsdt($fundingUsdt !== null ? sprintf('%.12F', $fundingUsdt) : null)
            ->setSpreadCostUsdt($spreadCostUsdt !== null ? sprintf('%.12F', $spreadCostUsdt) : null)
            ->setExchangeFillId($exchangeFillId)
            ->setSide($side)
            ->setQualityFlags($qualityFlags);
    }
}
