<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry\Service;

use App\Logging\Dto\LifecycleContextBuilder;
use App\TradeEntry\Dto\PreparedTradeEntry;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Types\Side;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PreparedTradeEntry::class)]
final class TradeEntryPreparationParityTest extends TestCase
{
    public function testStablePlanPayloadMatchesLegacySimulationShape(): void
    {
        $prepared = new PreparedTradeEntry(
            plan: new OrderPlanModel('BTCUSDT', Side::Long, 'limit', 'isolated', 4, 100.0, 98.0, 104.0, 2, 3, 2, 1.0),
            terminalResult: null,
            decisionKey: 'decision-1',
            internalTradeId: 'paper-trade-1',
            lifecycle: new LifecycleContextBuilder('BTCUSDT'),
            mode: 'regular',
            executionTimeframe: '1m',
        );

        self::assertSame([
            'symbol' => 'BTCUSDT',
            'side' => 'long',
            'entry' => 100.0,
            'stop' => 98.0,
            'take_profit' => 104.0,
            'size' => 2,
            'leverage' => 3,
        ], $prepared->stablePlanPayload());
    }

    public function testPreparedStateRequiresExactlyOnePlanOrTerminalResult(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prepared_trade_entry_state_invalid');
        new PreparedTradeEntry(null, null, 'decision', 'trade', new LifecycleContextBuilder('BTCUSDT'), 'regular', '1m');
    }
}
