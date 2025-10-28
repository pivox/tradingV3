<?php
declare(strict_types=1);

namespace App\Tests\TradeEntry;

use PHPUnit\Framework\TestCase;
use App\TradeEntry\TradeEntryBox;
use App\TradeEntry\PreOrder\PreOrderBuilder;
use App\TradeEntry\EntryZone\EntryZoneBox;
use App\TradeEntry\EntryZone\EntryZoneCalculator;
use App\TradeEntry\EntryZone\EntryZoneFilters;
use App\TradeEntry\RiskSizer\RiskSizerBox;
use App\TradeEntry\RiskSizer\LeverageCalculator;
use App\TradeEntry\OrderPlan\OrderPlanBox;
use App\TradeEntry\OrderPlan\OrderPlanBuilder;
use App\TradeEntry\Execution\ExecutionBox;
use App\TradeEntry\Types\Side;

final class TradeEntryBoxTest extends TestCase
{
    private TradeEntryBox $tradeEntryBox;

    protected function setUp(): void
    {
        $this->tradeEntryBox = new TradeEntryBox(
            new PreOrderBuilder(),
            new EntryZoneBox(
                new EntryZoneCalculator(),
                new EntryZoneFilters()
            ),
            new RiskSizerBox(
                new LeverageCalculator()
            ),
            new OrderPlanBox(
                new OrderPlanBuilder()
            ),
            new ExecutionBox()
        );
    }

    public function testHandleWithValidData(): void
    {
        $input = [
            'symbol' => 'BTCUSDT',
            'side' => Side::LONG,
            'entry_price_base' => 67250.0,
            'atr_value' => 35.0,
            'pivot_price' => 67220.0,
            'risk_pct' => 2.0,
            'budget_usdt' => 100.0,
            'equity_usdt' => 1000.0,
            'rsi' => 54.0,
            'volume_ratio' => 1.8,
            'pullback_confirmed' => true,
            'tick_size' => 0.1,
        ];

        $result = $this->tradeEntryBox->handle($input);

        $this->assertEquals('order_opened', $result->status);
        $this->assertArrayHasKey('order_id', $result->data);
        $this->assertArrayHasKey('symbol', $result->data);
        $this->assertArrayHasKey('side', $result->data);
        $this->assertArrayHasKey('price', $result->data);
        $this->assertArrayHasKey('quantity', $result->data);
        $this->assertArrayHasKey('sl_price', $result->data);
        $this->assertArrayHasKey('tp1_price', $result->data);
        $this->assertArrayHasKey('tp1_size_pct', $result->data);
        
        $this->assertEquals('BTCUSDT', $result->data['symbol']);
        $this->assertEquals('long', $result->data['side']);
        $this->assertEquals(67250.0, $result->data['price']);
    }

    public function testHandleWithInvalidRsi(): void
    {
        $input = [
            'symbol' => 'BTCUSDT',
            'side' => Side::LONG,
            'entry_price_base' => 67250.0,
            'atr_value' => 35.0,
            'pivot_price' => 67220.0,
            'risk_pct' => 2.0,
            'budget_usdt' => 100.0,
            'equity_usdt' => 1000.0,
            'rsi' => 85.0, // RSI trop élevé (> 70)
            'volume_ratio' => 1.8,
            'pullback_confirmed' => true,
            'tick_size' => 0.1,
        ];

        $result = $this->tradeEntryBox->handle($input);

        $this->assertEquals('cancelled', $result->status);
        $this->assertEquals('entry_zone_invalid_or_filters_failed', $result->data['reason']);
    }

    public function testHandleWithShortPosition(): void
    {
        $input = [
            'symbol' => 'ETHUSDT',
            'side' => Side::SHORT,
            'entry_price_base' => 3500.0,
            'atr_value' => 15.0,
            'pivot_price' => 3520.0,
            'risk_pct' => 1.5,
            'budget_usdt' => 200.0,
            'equity_usdt' => 2000.0,
            'rsi' => 45.0,
            'volume_ratio' => 2.0,
            'pullback_confirmed' => true,
            'tick_size' => 0.01,
        ];

        $result = $this->tradeEntryBox->handle($input);

        $this->assertEquals('order_opened', $result->status);
        $this->assertEquals('ETHUSDT', $result->data['symbol']);
        $this->assertEquals('short', $result->data['side']);
        $this->assertEquals(3500.0, $result->data['price']);
    }
}
