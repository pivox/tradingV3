<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\SlTp;

use App\TradingCore\SlTp\Dto\TakeProfitRequest;
use App\TradingCore\SlTp\Dto\TakeProfitResult;
use App\TradingCore\SlTp\Service\TakeProfitCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TakeProfitCalculator::class)]
#[CoversClass(TakeProfitRequest::class)]
#[CoversClass(TakeProfitResult::class)]
final class TakeProfitCalculatorTest extends TestCase
{
    public function testCalculatesTakeProfitInRForLong(): void
    {
        $calculator = new TakeProfitCalculator();

        $result = $calculator->calculate(new TakeProfitRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 95.0,
            riskDistance: null,
            rMultiple: 1.8,
            tp1R: 1.4,
            tpPolicy: 'r_multiple',
            tpBufferPct: null,
            tpMinKeepRatio: 0.95,
            tpMaxExtraR: 0.5,
            feesBps: null,
            spreadBps: null,
            slippageBps: null,
        ));

        self::assertSame(107.0, $result->tp1Price);
        self::assertSame(109.0, $result->tp2Price);
        self::assertSame(1.4, $result->expectedR);
        self::assertSame(1.4, $result->metadata['tp1_r']);
        self::assertSame(1.8, $result->metadata['r_multiple']);
    }

    public function testCalculatesTakeProfitInRForShort(): void
    {
        $calculator = new TakeProfitCalculator();

        $result = $calculator->calculate(new TakeProfitRequest(
            symbol: 'ETHUSDT',
            instrument: null,
            profile: 'regular',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'short',
            entryPrice: 100.0,
            stopPrice: 105.0,
            riskDistance: null,
            rMultiple: 1.8,
            tp1R: 1.4,
            tpPolicy: 'r_multiple',
            tpBufferPct: null,
            tpMinKeepRatio: 0.95,
            tpMaxExtraR: 0.5,
            feesBps: null,
            spreadBps: null,
            slippageBps: null,
        ));

        self::assertSame(93.0, $result->tp1Price);
        self::assertSame(91.0, $result->tp2Price);
        self::assertSame(1.4, $result->expectedR);
    }

    public function testAppliesTpBufferOnlyForPivotPolicy(): void
    {
        $calculator = new TakeProfitCalculator();
        $buffer = 0.001;

        // Pivot policy with an actual pivot selected: buffer is applied → tp1Price = 105 - 0.1 = 104.9.
        $pivotResult = $calculator->calculate(new TakeProfitRequest(
            symbol: 'SOLUSDT',
            instrument: null,
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 95.0,
            riskDistance: null,
            rMultiple: 1.0,
            tp1R: 1.0,
            tpPolicy: 'pivot_conservative',
            tpBufferPct: $buffer,
            tpMinKeepRatio: 0.95,
            tpMaxExtraR: 0.5,
            feesBps: null,
            spreadBps: null,
            slippageBps: null,
            pivotAligned: true,
        ));

        self::assertSame(104.9, $pivotResult->tp1Price);
        self::assertSame($buffer, $pivotResult->metadata['tp_buffer_pct']);

        // R-multiple policy: buffer must NOT be applied → tp1Price stays at 105.0.
        $rResult = $calculator->calculate(new TakeProfitRequest(
            symbol: 'SOLUSDT',
            instrument: null,
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 95.0,
            riskDistance: null,
            rMultiple: 1.0,
            tp1R: 1.0,
            tpPolicy: 'r_multiple',
            tpBufferPct: $buffer,
            tpMinKeepRatio: 0.95,
            tpMaxExtraR: null,
            feesBps: null,
            spreadBps: null,
            slippageBps: null,
        ));

        self::assertSame(105.0, $rResult->tp1Price);
    }

    public function testDoesNotApplyBufferWhenNoPivotWasSelected(): void
    {
        $calculator = new TakeProfitCalculator();

        // pivot_conservative policy but pivotAligned=false (no pivot available) → buffer must not shift TP.
        $result = $calculator->calculate(new TakeProfitRequest(
            symbol: 'SOLUSDT',
            instrument: null,
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 95.0,
            riskDistance: null,
            rMultiple: 2.0,
            tp1R: null,
            tpPolicy: 'pivot_conservative',
            tpBufferPct: 0.001,
            tpMinKeepRatio: 0.95,
            tpMaxExtraR: null,
            feesBps: null,
            spreadBps: null,
            slippageBps: null,
            pivotAligned: false,
        ));

        self::assertSame(110.0, $result->tp1Price);
    }

    public function testRejectsWhenShortTakeProfitPriceIsNotPositive(): void
    {
        $calculator = new TakeProfitCalculator();

        // short, entry=100, stop=180 → riskDistance=80, r=1.5 → tp=100-80*1.5=-20 → must throw.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('take-profit price must be positive');

        $calculator->calculate(new TakeProfitRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'short',
            entryPrice: 100.0,
            stopPrice: 180.0,
            riskDistance: null,
            rMultiple: 1.5,
            tp1R: null,
            tpPolicy: 'r_multiple',
            tpBufferPct: null,
            tpMinKeepRatio: 0.95,
            tpMaxExtraR: null,
            feesBps: null,
            spreadBps: null,
            slippageBps: null,
        ));
    }

    public function testRejectsWhenRMultipleIsNotPositive(): void
    {
        $calculator = new TakeProfitCalculator();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rMultiple must be positive');

        $calculator->calculate(new TakeProfitRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 95.0,
            riskDistance: null,
            rMultiple: 0.0,
            tp1R: null,
            tpPolicy: 'r_multiple',
            tpBufferPct: null,
            tpMinKeepRatio: 0.95,
            tpMaxExtraR: null,
            feesBps: null,
            spreadBps: null,
            slippageBps: null,
        ));
    }

    public function testSuppressesTp2WhenBufferLeavesItCloserThanRestoredTp1(): void
    {
        $calculator = new TakeProfitCalculator();

        // entry=100, stop=99.95 → riskDistance=0.05
        // tp1R=1 → tp1=100.05; tp2R=1.8 → tp2=100.09
        // buffer=0.001 → bufferAbs=0.1 (entry*buffer)
        // tp1=100.05-0.1=99.95, effectiveR=(99.95-100)/0.05=-1 < minKeep=0.95 → reset tp1=100.05
        // tp2=100.09-0.1=99.99 < tp1=100.05 → tp2 suppressed (not a farther target)
        $result = $calculator->calculate(new TakeProfitRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 99.95,
            riskDistance: null,
            rMultiple: 1.8,
            tp1R: 1.0,
            tpPolicy: 'pivot_conservative',
            tpBufferPct: 0.001,
            tpMinKeepRatio: 0.95,
            tpMaxExtraR: null,
            feesBps: null,
            spreadBps: null,
            slippageBps: null,
            pivotAligned: true,
        ));

        self::assertSame(100.05, $result->tp1Price);
        self::assertNull($result->tp2Price);
    }

    public function testSuppressesTp2WhenTp1RIsGreaterThanRMultiple(): void
    {
        $calculator = new TakeProfitCalculator();

        // tp1R=2.0 > rMultiple=1.5 → tp2 at 1.5R would be closer than tp1 at 2.0R — must be null.
        $result = $calculator->calculate(new TakeProfitRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 95.0,
            riskDistance: null,
            rMultiple: 1.5,
            tp1R: 2.0,
            tpPolicy: 'r_multiple',
            tpBufferPct: null,
            tpMinKeepRatio: 0.95,
            tpMaxExtraR: null,
            feesBps: null,
            spreadBps: null,
            slippageBps: null,
        ));

        self::assertSame(110.0, $result->tp1Price);
        self::assertNull($result->tp2Price);
    }

    public function testSuppressesTp2WhenTp1RAliasesRMultiple(): void
    {
        $calculator = new TakeProfitCalculator();

        // tp1R == rMultiple (regular.yaml alias pattern): must not emit a duplicate tp2 leg.
        $result = $calculator->calculate(new TakeProfitRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'regular',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 95.0,
            riskDistance: null,
            rMultiple: 2.0,
            tp1R: 2.0,
            tpPolicy: 'r_multiple',
            tpBufferPct: null,
            tpMinKeepRatio: 0.95,
            tpMaxExtraR: null,
            feesBps: null,
            spreadBps: null,
            slippageBps: null,
        ));

        self::assertSame(110.0, $result->tp1Price);
        self::assertNull($result->tp2Price);
    }

    public function testEmitsSingleTpWhenTp1RIsAbsent(): void
    {
        $calculator = new TakeProfitCalculator();

        // No explicit tp1R → tp1 lands at rMultiple, no tp2 emitted.
        $result = $calculator->calculate(new TakeProfitRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 95.0,
            riskDistance: null,
            rMultiple: 1.8,
            tp1R: null,
            tpPolicy: 'r_multiple',
            tpBufferPct: null,
            tpMinKeepRatio: 0.95,
            tpMaxExtraR: null,
            feesBps: null,
            spreadBps: null,
            slippageBps: null,
        ));

        self::assertSame(109.0, $result->tp1Price);
        self::assertNull($result->tp2Price);
        self::assertSame(1.8, $result->expectedR);
    }

    public function testWarnsWhenNetRIsIncoherentAfterCosts(): void
    {
        $calculator = new TakeProfitCalculator();

        $result = $calculator->calculate(new TakeProfitRequest(
            symbol: 'DOGEUSDT',
            instrument: null,
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 99.0,
            riskDistance: null,
            rMultiple: 0.2,
            tp1R: 0.2,
            tpPolicy: 'r_multiple',
            tpBufferPct: null,
            tpMinKeepRatio: 0.95,
            tpMaxExtraR: null,
            feesBps: 20.0,
            spreadBps: 20.0,
            slippageBps: 20.0,
        ));

        self::assertSame(-0.4, $result->expectedNetR);
        self::assertContains('expectedNetR is not positive after fees/spread/slippage.', $result->warnings);
    }
}
