<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry\MessageHandler;

use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Registry\ExchangeAdapterRegistry;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\Tests\TradeEntry\Support\ScriptedExchangeAdapter;
use App\TradeEntry\Execution\EmergencyCloseService;
use App\TradeEntry\Execution\ProtectionEnforcer;
use App\TradeEntry\Execution\RestingEntryWatcher;
use App\TradeEntry\Message\CancelOrderMessage;
use App\TradeEntry\MessageHandler\CancelOrderMessageHandler;
use App\TradeEntry\Service\TradeEntryMetricsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

#[CoversClass(CancelOrderMessageHandler::class)]
final class CancelOrderMessageHandlerTest extends TestCase
{
    private ScriptedExchangeAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new ScriptedExchangeAdapter();
    }

    public function testRestingOrderIsCancelledThroughTheAdapterAndTheSwitchReleased(): void
    {
        $this->adapter->openOrders[] = $this->adapter->order('ord-c', ExchangeOrderStatus::OPEN, 0.0, 1.0, 'client-c');
        $switch = $this->createMock(MtfSwitchRepository::class);
        $switch->expects(self::once())->method('turnOffSymbolForDuration')->with('BTCUSDT', '15m');

        $this->handler($switch)(new CancelOrderMessage('BTCUSDT', 'ord-c', 'client-c', 'decision-c'));

        self::assertCount(1, $this->adapter->cancelled);
        self::assertSame('ord-c', $this->adapter->cancelled[0]->exchangeOrderId);
        self::assertSame('protective', $this->adapter->cancelled[0]->metadata['write_kind']);
    }

    public function testFilledOrderIsNotCancelled(): void
    {
        $this->adapter->openOrders[] = $this->adapter->order('ord-c', ExchangeOrderStatus::PARTIALLY_FILLED, 0.5, 0.5, 'client-c');
        $switch = $this->createMock(MtfSwitchRepository::class);
        $switch->expects(self::never())->method('turnOffSymbolForDuration');

        $this->handler($switch)(new CancelOrderMessage('BTCUSDT', 'ord-c', 'client-c'));

        self::assertSame([], $this->adapter->cancelled);
    }

    public function testAlreadyClosedOrderIsLeftAlone(): void
    {
        $switch = $this->createMock(MtfSwitchRepository::class);
        $switch->expects(self::never())->method('turnOffSymbolForDuration');

        $this->handler($switch)(new CancelOrderMessage('BTCUSDT', 'ord-gone', 'client-c'));

        self::assertSame([], $this->adapter->cancelled);
    }

    private function handler(MtfSwitchRepository $switch): CancelOrderMessageHandler
    {
        $logger = new NullLogger();
        $metrics = new TradeEntryMetricsService();
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };

        return new CancelOrderMessageHandler(
            new ExchangeAdapterRegistry([$this->adapter]),
            new RestingEntryWatcher(new ProtectionEnforcer(new EmergencyCloseService($metrics, $logger), $metrics, $logger), $clock),
            $switch,
            $logger,
            '15m',
        );
    }
}
