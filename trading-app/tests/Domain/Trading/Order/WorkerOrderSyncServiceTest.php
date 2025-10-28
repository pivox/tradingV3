<?php

declare(strict_types=1);

namespace App\Tests\Domain\Trading\Order;

use App\Domain\Trading\Order\Dto\WorkerOrderSignalDto;
use App\Domain\Trading\Order\WorkerOrderSyncService;
use App\Entity\ExchangeOrder;
use App\Entity\OrderLifecycle;
use App\Entity\OrderPlan;
use App\Repository\ExchangeOrderRepository;
use App\Repository\OrderLifecycleRepository;
use App\Repository\OrderPlanRepository;
use App\Repository\PositionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class WorkerOrderSyncServiceTest extends TestCase
{
    public function testSyncCreatesExchangeOrderAndUpdatesPlan(): void
    {
        $signal = WorkerOrderSignalDto::fromArray([
            'kind' => 'ENTRY',
            'status' => 'SUBMITTED',
            'client_order_id' => 'MTF_BTC_OPEN_123',
            'symbol' => 'BTCUSDT',
            'side' => 'buy_open_long',
            'type' => 'limit',
            'price' => '57000.5',
            'size' => '0.10',
            'submitted_at' => '2024-03-20T12:34:56Z',
            'plan' => ['id' => 1],
            'position' => ['side' => 'LONG'],
            'context' => ['source' => 'test'],
            'exchange_response' => ['code' => 0],
            'trace_id' => 'abc123',
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $orderLifecycleRepository = $this->createMock(OrderLifecycleRepository::class);
        $orderPlanRepository = $this->createMock(OrderPlanRepository::class);
        $positionRepository = $this->createMock(PositionRepository::class);
        $exchangeOrderRepository = $this->createMock(ExchangeOrderRepository::class);
        $clock = $this->createMock(ClockInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $orderLifecycleRepository->method('findOneByOrderId')->willReturn(null);
        $orderLifecycleRepository->method('findOneByClientOrderId')->willReturn(null);
        $exchangeOrderRepository->method('findOneByClientOrderId')->willReturn(null);

        $orderPlan = new OrderPlan();
        $this->setEntityId($orderPlan, 1);
        $orderPlanRepository->expects($this->once())->method('find')->with(1)->willReturn($orderPlan);

        $positionRepository->method('findOneBySymbolSide')->willReturn(null);

        $entityManager->expects($this->atLeastOnce())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $clock->method('now')->willReturn(new \DateTimeImmutable('2024-03-20T12:35:00Z'));

        $service = new WorkerOrderSyncService(
            $entityManager,
            $orderLifecycleRepository,
            $orderPlanRepository,
            $positionRepository,
            $exchangeOrderRepository,
            $clock,
            $logger
        );

        $result = $service->sync($signal);

        self::assertInstanceOf(OrderLifecycle::class, $result->orderLifecycle);
        self::assertInstanceOf(ExchangeOrder::class, $result->exchangeOrder);
        self::assertFalse($result->duplicate);
        self::assertSame('EXECUTED', $orderPlan->getStatus());
        self::assertSame('SUBMITTED', $result->exchangeOrder->getStatus());
        self::assertSame('MTF_BTC_OPEN_123', $result->exchangeOrder->getClientOrderId());
        self::assertSame($orderPlan, $result->exchangeOrder->getOrderPlan());
    }

    public function testSyncDetectsDuplicate(): void
    {
        $signal = WorkerOrderSignalDto::fromArray([
            'kind' => 'ENTRY',
            'status' => 'SUBMITTED',
            'client_order_id' => 'MTF_ETH_OPEN_456',
            'order_id' => '999999',
            'symbol' => 'ETHUSDT',
            'side' => 'buy_open_long',
            'type' => 'limit',
            'price' => '3200.0',
            'size' => '0.50',
            'submitted_at' => '2024-03-20T13:00:00Z',
            'plan' => ['id' => 2],
            'position' => ['side' => 'LONG'],
            'context' => [],
            'exchange_response' => ['code' => 0],
            'trace_id' => 'trace-dup',
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $orderLifecycleRepository = $this->createMock(OrderLifecycleRepository::class);
        $orderPlanRepository = $this->createMock(OrderPlanRepository::class);
        $positionRepository = $this->createMock(PositionRepository::class);
        $exchangeOrderRepository = $this->createMock(ExchangeOrderRepository::class);
        $clock = $this->createMock(ClockInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $orderLifecycle = new OrderLifecycle(
            '999999',
            'ETHUSDT',
            'SUBMITTED',
            'MTF_ETH_OPEN_456',
            'buy_open_long',
            'limit',
            $signal->submittedAt
        );
        $orderLifecycleRepository->method('findOneByOrderId')->willReturn($orderLifecycle);
        $orderLifecycleRepository->method('findOneByClientOrderId')->willReturn($orderLifecycle);

        $exchangeOrder = new ExchangeOrder('MTF_ETH_OPEN_456', 'ETHUSDT', 'ENTRY');
        $exchangeOrder->setOrderId('999999')->setStatus('SUBMITTED');
        $exchangeOrderRepository->method('findOneByClientOrderId')->willReturn($exchangeOrder);

        $orderPlan = new OrderPlan();
        $this->setEntityId($orderPlan, 2);
        $orderPlanRepository->method('find')->willReturn($orderPlan);

        $positionRepository->method('findOneBySymbolSide')->willReturn(null);

        $entityManager->expects($this->atLeastOnce())->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $clock->method('now')->willReturn(new \DateTimeImmutable('2024-03-20T13:00:01Z'));

        $service = new WorkerOrderSyncService(
            $entityManager,
            $orderLifecycleRepository,
            $orderPlanRepository,
            $positionRepository,
            $exchangeOrderRepository,
            $clock,
            $logger
        );

        $result = $service->sync($signal);

        self::assertTrue($result->duplicate);
        self::assertSame($exchangeOrder, $result->exchangeOrder);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
