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
use App\Contract\Provider\MainProviderInterface;
use Psr\Log\LoggerInterface;

final class TradeEntryIntegrationTest extends TestCase
{
    public function testFullIntegrationWithMockProvider(): void
    {
        // Mock MainProvider
        $mockMainProvider = $this->createMock(MainProviderInterface::class);
        $mockOrderProvider = $this->createMock(\App\Contract\Provider\OrderProviderInterface::class);
        $mockMainProvider->method('getOrderProvider')->willReturn($mockOrderProvider);

        // Mock Logger
        $mockLogger = $this->createMock(LoggerInterface::class);

        // Configuration des mocks
        $mockOrderProvider->method('placeOrder')->willReturn(
            new \App\Contract\Provider\Dto\OrderDto(
                orderId: 'TEST-123456',
                symbol: 'BTCUSDT',
                side: \App\Common\Enum\OrderSide::BUY,
                type: \App\Common\Enum\OrderType::LIMIT,
                status: \App\Common\Enum\OrderStatus::NEW,
                quantity: \Brick\Math\BigDecimal::of('0.001'),
                price: \Brick\Math\BigDecimal::of('67250.0'),
                stopPrice: null,
                filledQuantity: \Brick\Math\BigDecimal::of('0'),
                remainingQuantity: \Brick\Math\BigDecimal::of('0.001'),
                averagePrice: null,
                createdAt: new \DateTimeImmutable()
            )
        );

        // Construction du TradeEntryBox
        $tradeEntryBox = new TradeEntryBox(
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
            new ExecutionBox(
                $mockMainProvider,
                $mockLogger
            ),
            $mockLogger
        );

        // Test avec données valides
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
        ];

        $result = $tradeEntryBox->handle($input);

        $this->assertEquals('order_opened', $result->status);
        $this->assertArrayHasKey('order_id', $result->data);
        $this->assertEquals('TEST-123456', $result->data['order_id']);
        $this->assertEquals('BTCUSDT', $result->data['symbol']);
        $this->assertEquals('buy', $result->data['side']);
    }

    public function testIntegrationWithInvalidRsi(): void
    {
        // Mock MainProvider
        $mockMainProvider = $this->createMock(MainProviderInterface::class);
        $mockLogger = $this->createMock(LoggerInterface::class);

        $tradeEntryBox = new TradeEntryBox(
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
            new ExecutionBox(
                $mockMainProvider,
                $mockLogger
            ),
            $mockLogger
        );

        // Test avec RSI trop élevé
        $input = [
            'symbol' => 'BTCUSDT',
            'side' => Side::LONG,
            'entry_price_base' => 67250.0,
            'atr_value' => 35.0,
            'pivot_price' => 67220.0,
            'risk_pct' => 2.0,
            'budget_usdt' => 100.0,
            'equity_usdt' => 1000.0,
            'rsi' => 85.0, // RSI trop élevé
            'volume_ratio' => 1.8,
            'pullback_confirmed' => true,
        ];

        $result = $tradeEntryBox->handle($input);

        $this->assertEquals('cancelled', $result->status);
        $this->assertEquals('entry_zone_invalid_or_filters_failed', $result->data['reason']);
    }
}
