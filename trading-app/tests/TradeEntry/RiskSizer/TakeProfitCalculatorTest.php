<?php
declare(strict_types=1);

namespace App\Tests\TradeEntry\RiskSizer;

use App\TradeEntry\RiskSizer\TakeProfitCalculator;
use App\TradeEntry\Types\Side;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TakeProfitCalculatorTest extends TestCase
{
    private TakeProfitCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TakeProfitCalculator(new NullLogger());
    }

    public function testFromRMultipleWithFeesLong(): void
    {
        // entry=100, stop=95 → riskUnit=5, feeOffset=100*(0.0005+0.0005)=0.1
        // TP = 100 + 1.8*5 + 0.1 = 109.1
        $result = $this->calculator->fromRMultipleWithFees(
            100.0, 95.0, Side::Long, 1.8, 0.0005, 0.0005, 2
        );
        $this->assertSame(109.1, $result);
    }

    public function testFromRMultipleWithFeesShort(): void
    {
        // entry=100, stop=105 → riskUnit=5, feeOffset=0.1
        // TP = 100 - 1.8*5 - 0.1 = 90.9
        $result = $this->calculator->fromRMultipleWithFees(
            100.0, 105.0, Side::Short, 1.8, 0.0005, 0.0005, 2
        );
        $this->assertSame(90.9, $result);
    }

    public function testFromRMultipleWithFeesZeroRMultiple(): void
    {
        // When rMultiple is 0, returns entry price (safe no-op, mirrors fromRMultiple behaviour)
        $result = $this->calculator->fromRMultipleWithFees(
            100.0, 95.0, Side::Long, 0.0, 0.0005, 0.0005, 2
        );
        $this->assertSame(100.0, $result);
    }

    public function testFromRMultipleWithFeesEntryEqualsStop(): void
    {
        // riskUnit = 0 → safe no-op: returns entry price (not fee-inflated entry)
        $result = $this->calculator->fromRMultipleWithFees(
            100.0, 100.0, Side::Long, 1.8, 0.0005, 0.0005, 2
        );
        $this->assertSame(100.0, $result);
    }

    public function testFromRMultipleWithFeesZeroFees(): void
    {
        // With zero fees, result should equal fromRMultiple for the same inputs
        $withFees = $this->calculator->fromRMultipleWithFees(
            100.0, 95.0, Side::Long, 1.8, 0.0, 0.0, 2
        );
        $withoutFees = $this->calculator->fromRMultiple(
            100.0, 95.0, Side::Long, 1.8, 2
        );
        $this->assertSame($withoutFees, $withFees);
    }
}
