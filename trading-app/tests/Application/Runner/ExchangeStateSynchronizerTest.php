<?php

declare(strict_types=1);

namespace App\Tests\Application\Runner;

use App\Application\Runner\ExchangeStateSynchronizer;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderStatus;
use App\Common\Enum\OrderType;
use App\Common\Enum\PositionSide;
use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\PositionDto;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Entity\Position;
use App\Provider\Context\ExchangeContext;
use App\Repository\PositionRepository;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ExchangeStateSynchronizer::class)]
final class ExchangeStateSynchronizerTest extends TestCase
{
    public function testReturnsEmptyListsWhenProvidersAreMissing(): void
    {
        $context = $this->legacyContext();

        $mainProvider = $this->createMock(MainProviderInterface::class);
        $mainProvider->expects(self::once())->method('forContext')->with($context)->willReturnSelf();
        $mainProvider->expects(self::once())->method('getAccountProvider')->willReturn(null);
        $mainProvider->expects(self::once())->method('getOrderProvider')->willReturn(null);

        $positionRepository = $this->createMock(PositionRepository::class);
        $positionRepository->expects(self::never())->method('upsert');

        $synchronizer = new ExchangeStateSynchronizer(
            $mainProvider,
            $positionRepository,
            $this->createMock(LoggerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame(
            ['open_positions' => [], 'open_orders' => []],
            $synchronizer->sync($context),
        );
    }

    public function testSynchronizesPositionsAndReturnsOpenOrders(): void
    {
        $context = $this->legacyContext();
        $positionDto = $this->positionDto();
        $orderDto = $this->orderDto();

        $accountProvider = $this->createMock(AccountProviderInterface::class);
        $accountProvider->expects(self::once())->method('getOpenPositions')->willReturn([$positionDto]);

        $orderProvider = $this->createMock(OrderProviderInterface::class);
        $orderProvider->expects(self::once())->method('getOpenOrders')->willReturn([$orderDto]);

        $mainProvider = $this->createMock(MainProviderInterface::class);
        $mainProvider->expects(self::once())->method('forContext')->with($context)->willReturnSelf();
        $mainProvider->expects(self::once())->method('getAccountProvider')->willReturn($accountProvider);
        $mainProvider->expects(self::once())->method('getOrderProvider')->willReturn($orderProvider);

        $positionRepository = $this->createMock(PositionRepository::class);
        $positionRepository
            ->expects(self::once())
            ->method('findOneBySymbolSide')
            ->with('BTCUSDT', 'LONG', $context)
            ->willReturn(null);
        $positionRepository
            ->expects(self::once())
            ->method('upsert')
            ->with(self::callback(static function (Position $position): bool {
                return $position->getSymbol() === 'BTCUSDT'
                    && $position->getSide() === 'LONG'
                    && $position->getSize() === '12'
                    && $position->getAvgEntryPrice() === '42000'
                    && $position->getLeverage() === 3
                    && $position->getUnrealizedPnl() === '7.5'
                    && $position->getPayload()['exchange'] === 'bitmart'
                    && $position->getPayload()['market_type'] === 'perpetual'
                    && $position->getPayload()['mark_price'] === '42100';
            }));

        $synchronizer = new ExchangeStateSynchronizer(
            $mainProvider,
            $positionRepository,
            $this->createMock(LoggerInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame(
            ['open_positions' => [$positionDto], 'open_orders' => [$orderDto]],
            $synchronizer->sync($context),
        );
    }

    private function legacyContext(): ExchangeContext
    {
        return new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL);
    }

    private function positionDto(): PositionDto
    {
        return new PositionDto(
            symbol: 'btcusdt',
            side: PositionSide::LONG,
            size: BigDecimal::of('12'),
            entryPrice: BigDecimal::of('42000'),
            markPrice: BigDecimal::of('42100'),
            unrealizedPnl: BigDecimal::of('7.5'),
            realizedPnl: BigDecimal::of('1.25'),
            margin: BigDecimal::of('140'),
            leverage: BigDecimal::of('3'),
            openedAt: new \DateTimeImmutable('2025-01-01 00:00:00 UTC'),
        );
    }

    private function orderDto(): OrderDto
    {
        return new OrderDto(
            orderId: 'order-1',
            symbol: 'ETHUSDT',
            side: OrderSide::BUY,
            type: OrderType::LIMIT,
            status: OrderStatus::PENDING,
            quantity: BigDecimal::of('1'),
            price: BigDecimal::of('3000'),
            stopPrice: null,
            filledQuantity: BigDecimal::of('0'),
            remainingQuantity: BigDecimal::of('1'),
            averagePrice: null,
            createdAt: new \DateTimeImmutable('2025-01-01 00:00:00 UTC'),
        );
    }
}
