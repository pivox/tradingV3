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

    public function testCarriesTpBufferWithoutMutatingConfiguredValue(): void
    {
        $calculator = new TakeProfitCalculator();
        $buffer = 0.001;

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
            rMultiple: 1.0,
            tp1R: 1.0,
            tpPolicy: 'pivot_conservative',
            tpBufferPct: $buffer,
            tpMinKeepRatio: 0.95,
            tpMaxExtraR: 0.5,
            feesBps: null,
            spreadBps: null,
            slippageBps: null,
        ));

        self::assertSame(104.9, $result->tp1Price);
        self::assertSame($buffer, $result->metadata['tp_buffer_pct']);
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
