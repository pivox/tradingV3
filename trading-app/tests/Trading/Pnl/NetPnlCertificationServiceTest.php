<?php

declare(strict_types=1);

namespace App\Tests\Trading\Pnl;

use App\Trading\Pnl\NetPnlCertificationService;
use App\Trading\Pnl\TradeCosts;
use App\Trading\Pnl\TradeFill;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NetPnlCertificationService::class)]
#[CoversClass(TradeCosts::class)]
#[CoversClass(TradeFill::class)]
final class NetPnlCertificationServiceTest extends TestCase
{
    public function testCertifiesCompleteWinningTradeWithKnownCosts(): void
    {
        $service = new NetPnlCertificationService();

        $result = $service->certify(
            entryFills: [
                new TradeFill('entry-1', 'BUY', 0.4, 100.0, 0.02, 'USDT', 'maker', new \DateTimeImmutable('2026-06-25 10:00:00 UTC')),
                new TradeFill('entry-2', 'BUY', 0.6, 101.0, 0.03, 'USDT', 'maker', new \DateTimeImmutable('2026-06-25 10:00:10 UTC')),
            ],
            exitFills: [
                new TradeFill('exit-1', 'SELL', 1.0, 110.2, 0.05, 'USDT', 'taker', new \DateTimeImmutable('2026-06-25 10:15:00 UTC')),
            ],
            costs: new TradeCosts(
                otherTradingFeesUsdt: 0.01,
                fundingUsdt: 0.20,
                spreadCostUsdt: 0.10,
                slippageCostUsdt: 0.15,
                borrowCostUsdt: 0.0,
                liquidationFeeUsdt: 0.0,
            ),
            side: 'LONG',
            positionFullyClosed: true,
            lineageSufficient: true,
            identifierConflict: false,
            riskUsdtAtEntry: 5.0,
        );

        self::assertTrue($result->certified);
        self::assertSame('complete', $result->costCompleteness);
        self::assertEqualsWithDelta(9.6, $result->grossRealizedPnlUsdt, 1e-9);
        self::assertEqualsWithDelta(0.05, $result->entryFeeUsdt, 1e-9);
        self::assertEqualsWithDelta(0.05, $result->exitFeeUsdt, 1e-9);
        self::assertEqualsWithDelta(9.44, $result->netPnlUsdt, 1e-9);
        self::assertEqualsWithDelta(1.888, $result->realizedNetPnlR, 1e-9);
        self::assertSame([], $result->qualityFlags);
    }

    public function testRefusesCertificationWhenFeeCurrencyIsNotNormalized(): void
    {
        $service = new NetPnlCertificationService();

        $result = $service->certify(
            entryFills: [
                new TradeFill('entry-1', 'BUY', 1.0, 100.0, 0.01, 'BTC', 'maker', new \DateTimeImmutable('2026-06-25 10:00:00 UTC')),
            ],
            exitFills: [
                new TradeFill('exit-1', 'SELL', 1.0, 99.0, 0.01, 'USDT', 'taker', new \DateTimeImmutable('2026-06-25 10:05:00 UTC')),
            ],
            costs: TradeCosts::zeroKnown(),
            side: 'LONG',
            positionFullyClosed: true,
            lineageSufficient: true,
            identifierConflict: false,
            riskUsdtAtEntry: 4.0,
        );

        self::assertFalse($result->certified);
        self::assertNull($result->netPnlUsdt);
        self::assertSame('partial', $result->costCompleteness);
        self::assertContains('fee_currency_not_normalized', $result->qualityFlags);
    }

    public function testRefusesOpenPositionAndQuantityMismatch(): void
    {
        $service = new NetPnlCertificationService();

        $result = $service->certify(
            entryFills: [
                new TradeFill('entry-1', 'BUY', 2.0, 100.0, 0.02, 'USDT', 'maker', new \DateTimeImmutable('2026-06-25 10:00:00 UTC')),
            ],
            exitFills: [
                new TradeFill('exit-1', 'SELL', 1.2, 102.0, 0.02, 'USDT', 'taker', new \DateTimeImmutable('2026-06-25 10:10:00 UTC')),
            ],
            costs: TradeCosts::zeroKnown(),
            side: 'LONG',
            positionFullyClosed: false,
            lineageSufficient: true,
            identifierConflict: false,
            riskUsdtAtEntry: 10.0,
        );

        self::assertFalse($result->certified);
        self::assertNull($result->netPnlUsdt);
        self::assertContains('position_not_fully_closed', $result->qualityFlags);
        self::assertContains('quantity_mismatch', $result->qualityFlags);
    }

    public function testTradeFillRejectsZeroQuantityBeforeCertification(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fill quantity must be positive');

        new TradeFill('entry-1', 'BUY', 0.0, 100.0, 0.0, 'USDT', 'maker', new \DateTimeImmutable('2026-06-25 10:00:00 UTC'));
    }

    public function testClassifiesCostOnlyEvidenceAsPartial(): void
    {
        $service = new NetPnlCertificationService();

        $result = $service->certify(
            entryFills: [],
            exitFills: [],
            costs: new TradeCosts(
                otherTradingFeesUsdt: null,
                fundingUsdt: 0.15,
                spreadCostUsdt: null,
                slippageCostUsdt: null,
                borrowCostUsdt: null,
                liquidationFeeUsdt: null,
            ),
            side: 'LONG',
            positionFullyClosed: true,
            lineageSufficient: true,
            identifierConflict: false,
        );

        self::assertFalse($result->certified);
        self::assertSame('partial', $result->costCompleteness);
        self::assertNull($result->netPnlUsdt);
        self::assertContains('missing_entry_fill', $result->qualityFlags);
        self::assertContains('missing_gross_pnl', $result->qualityFlags);
    }

    public function testClassifiesNoFillsAndNoCostEvidenceAsUnknown(): void
    {
        $service = new NetPnlCertificationService();

        $result = $service->certify(
            entryFills: [],
            exitFills: [],
            costs: new TradeCosts(
                otherTradingFeesUsdt: null,
                fundingUsdt: null,
                spreadCostUsdt: null,
                slippageCostUsdt: null,
                borrowCostUsdt: null,
                liquidationFeeUsdt: null,
            ),
            side: 'LONG',
            positionFullyClosed: true,
            lineageSufficient: true,
            identifierConflict: false,
        );

        self::assertFalse($result->certified);
        self::assertSame('unknown', $result->costCompleteness);
        self::assertNull($result->netPnlUsdt);
    }

    public function testRefusesExchangeOrderSideInsteadOfPositionSide(): void
    {
        $service = new NetPnlCertificationService();

        $result = $service->certify(
            entryFills: [
                new TradeFill('entry-1', 'BUY', 1.0, 100.0, 0.01, 'USDT', 'maker', new \DateTimeImmutable('2026-06-25 10:00:00 UTC')),
            ],
            exitFills: [
                new TradeFill('exit-1', 'SELL', 1.0, 110.0, 0.01, 'USDT', 'taker', new \DateTimeImmutable('2026-06-25 10:10:00 UTC')),
            ],
            costs: TradeCosts::zeroKnown(),
            side: 'SELL',
            positionFullyClosed: true,
            lineageSufficient: true,
            identifierConflict: false,
            riskUsdtAtEntry: 5.0,
        );

        self::assertFalse($result->certified);
        self::assertNull($result->grossRealizedPnlUsdt);
        self::assertNull($result->netPnlUsdt);
        self::assertContains('invalid_side', $result->qualityFlags);
    }
}
