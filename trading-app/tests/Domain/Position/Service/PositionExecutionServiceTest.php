<?php

declare(strict_types=1);

namespace App\Tests\Domain\Position\Service;

use App\Domain\Common\Enum\SignalSide;
use App\Domain\Position\Dto\PositionConfigDto;
use App\Domain\Position\Dto\PositionOpeningDto;
use App\Domain\Position\Service\PositionExecutionService;
use App\Domain\Ports\Out\TradingProviderPort;
use App\Domain\Trading\Exposure\ActiveExposureGuard;
use App\Domain\Trading\Order\OrderLifecycleService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PositionExecutionServiceTest extends TestCase
{
    public function testExecutePositionPlacesOrders(): void
    {
        $provider = new class implements TradingProviderPort {
            public array $setLeverageCalls = [];
            public array $submitOrderCalls = [];
            public array $tpSlCalls = [];

            public function submitOrder(array $orderData): array
            {
                $this->submitOrderCalls[] = $orderData;
                return ['code' => 1000, 'data' => ['order_id' => 'MAIN_ORDER']];
            }

            public function cancelOrder(string $symbol, string $orderId): array { return []; }
            public function cancelAllOrders(string $symbol): array { return []; }
            public function getPositions(?string $symbol = null): array { return []; }
            public function getAssetsDetail(): array { return []; }
            public function healthCheck(): array { return []; }

            public function setLeverage(string $symbol, int $leverage, string $openType = 'cross'): array
            {
                $this->setLeverageCalls[] = [$symbol, $leverage, $openType];
                return ['code' => 1000, 'data' => []];
            }

            public function submitTpSlOrder(array $payload): array
            {
                $this->tpSlCalls[] = $payload;
                $id = $payload['orderType'] === 'stop_loss' ? 'SL_ORDER' : 'TP_ORDER';
                return ['code' => 1000, 'data' => ['order_id' => $id]];
            }

            public function getOpenOrders(?string $symbol = null): array
            {
                return ['orders' => [], 'plan_orders' => []];
            }
        };

        $guard = new class extends ActiveExposureGuard {
            public function __construct() {}
            public function assertEligible(string $symbol, SignalSide $side): void {}
        };

        $orderLifecycle = new class extends OrderLifecycleService {
            public function __construct() {}
            public function registerEntryOrder(array $order, array $context): void {}
            public function registerProtectiveOrder(array $order, string $kind): void {}
            public function handleEvent(array $event): void {}
        };

        $clock = new class implements \Psr\Clock\ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2025-01-01T00:00:00Z');
            }
        };
        $service = new PositionExecutionService($provider, $guard, $orderLifecycle, new NullLogger(), $clock);

        $config = new PositionConfigDto(
            defaultRiskPercent: 2.0,
            maxRiskPercent: 5.0,
            slAtrMultiplier: 2.0,
            tpAtrMultiplier: 4.0,
            maxPositionSize: 100.0,
            orderType: 'MARKET',
            timeInForce: 'GTC',
            enablePartialFills: true,
            minOrderSize: 0.01,
            maxOrderSize: 200.0,
            enableStopLoss: true,
            enableTakeProfit: true,
            openType: 'cross'
        );

        $position = new PositionOpeningDto(
            symbol: 'BTCUSDT',
            side: SignalSide::LONG,
            leverage: 3.6,
            positionSize: 10.0,
            entryPrice: 50000.0,
            stopLossPrice: 49500.0,
            takeProfitPrice: 52000.0,
            riskAmount: 100.0,
            potentialProfit: 200.0,
            riskMetrics: [],
            executionParams: ['open_type' => 'cross']
        );

        $result = $service->executePosition($position, $config);

        self::assertSame('success', $result['status']);
        self::assertCount(1, $provider->setLeverageCalls);
        [$symbol, $leverage, $openType] = $provider->setLeverageCalls[0];
        self::assertSame('BTCUSDT', $symbol);
        self::assertSame(4, $leverage); // rounded from 3.6
        self::assertSame('cross', $openType);

        self::assertCount(1, $provider->submitOrderCalls);
        $orderPayload = $provider->submitOrderCalls[0];
        self::assertSame('BTCUSDT', $orderPayload['symbol']);
        self::assertSame(1, $orderPayload['side']);
        self::assertSame('market', $orderPayload['type']);
        self::assertArrayHasKey('client_order_id', $orderPayload);

        self::assertCount(2, $provider->tpSlCalls);
        $orderTypes = array_column($provider->tpSlCalls, 'orderType');
        self::assertContains('stop_loss', $orderTypes);
        self::assertContains('take_profit', $orderTypes);

        foreach ($provider->tpSlCalls as $tpSlPayload) {
            self::assertSame('BTCUSDT', $tpSlPayload['symbol']);
            self::assertArrayHasKey('client_order_id', $tpSlPayload);
            self::assertSame(3, $tpSlPayload['side']);
            self::assertSame('market', $tpSlPayload['category']);
        }
    }
}
