<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Event;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\FillCostLedgerEntry;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Event\DoctrineExchangeLocalProjectionStore;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeOrderUpdated;
use App\MtfRunner\Service\FuturesOrderSyncService;
use App\Repository\FillCostLedgerEntryRepository;
use App\Repository\FuturesOrderRepository;
use App\Repository\PositionRepository;
use App\Repository\TradeLineageRepository;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Pnl\FillCostLedgerIngestionConflict;
use App\Trading\Pnl\FillCostLedgerIngestionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DoctrineExchangeLocalProjectionStore::class)]
final class DoctrineExchangeLocalProjectionStoreTest extends TestCase
{
    public function testOrderProjectionFailsWhenLegacySyncReturnsNull(): void
    {
        $orderSync = $this->createMock(FuturesOrderSyncService::class);
        $orderSync->expects(self::once())
            ->method('syncOrderFromApi')
            ->willReturn(null);

        $store = $this->store($orderSync, $this->createStub(FillCostLedgerEntryRepository::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exchange_order_projection_failed');

        $store->project(new ExchangeOrderUpdated(new ExchangeOrderDto(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'order-1',
            clientOrderId: null,
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            status: ExchangeOrderStatus::OPEN,
            quantity: 1.0,
            filledQuantity: 0.0,
            remainingQuantity: 1.0,
            price: 100.0,
            averagePrice: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            timeInForce: null,
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
        ), new \DateTimeImmutable('2026-01-01 00:00:01 UTC')));
    }

    public function testFillProjectionPersistsLedgerBeforeNullLegacySyncAndReplayIsIdempotent(): void
    {
        $orderSync = $this->createMock(FuturesOrderSyncService::class);
        $orderSync->expects(self::exactly(2))
            ->method('syncTradeFromApi')
            ->willReturn(null);

        $savedEntry = null;
        $ledgerRepository = $this->createMock(FillCostLedgerEntryRepository::class);
        $ledgerRepository->expects(self::exactly(2))
            ->method('findOneByIdempotencyKey')
            ->with('fake:perpetual:exchange_fill:fill-replay')
            ->willReturnCallback(static function () use (&$savedEntry): ?FillCostLedgerEntry {
                return $savedEntry;
            });
        $ledgerRepository->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (FillCostLedgerEntry $entry) use (&$savedEntry): void {
                $savedEntry = $entry;
            });

        $store = $this->store($orderSync, $ledgerRepository);
        $event = new ExchangeFillReceived(new ExchangeFillDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: '',
            clientOrderId: null,
            fillId: 'fill-replay',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            quantity: 1.0,
            price: 100.0,
            fee: 0.01,
            feeCurrency: 'USDT',
            filledAt: new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
        ));

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $store->project($event);
                self::fail('A null legacy fill projection must fail closed.');
            } catch (\RuntimeException $exception) {
                self::assertSame('exchange_fill_projection_failed', $exception->getMessage());
            }
        }

        self::assertInstanceOf(FillCostLedgerEntry::class, $savedEntry);
    }

    public function testFillLedgerConflictPreventsLegacyTradeSync(): void
    {
        $orderSync = $this->createMock(FuturesOrderSyncService::class);
        $orderSync->expects(self::never())->method('syncTradeFromApi');

        $ledgerRepository = $this->createMock(FillCostLedgerEntryRepository::class);
        $ledgerRepository->expects(self::once())
            ->method('findOneByIdempotencyKey')
            ->with('fake:perpetual:exchange_fill:fill-conflict')
            ->willReturn(new FillCostLedgerEntry(
                idempotencyKey: 'fake:perpetual:exchange_fill:fill-conflict',
                payloadHash: str_repeat('0', 64),
                exchange: 'fake',
                marketType: 'perpetual',
                symbol: 'BTCUSDT',
                fillId: 'fill-conflict',
                fillRole: 'entry',
                occurredAt: new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
                source: 'test',
                sourceVersion: 'test_v1',
            ));

        $registry = $this->createStub(ManagerRegistry::class);
        $lineage = new TradeLineageManager(
            new TradeLineageRepository($registry),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
        $store = new DoctrineExchangeLocalProjectionStore(
            $orderSync,
            new FuturesOrderRepository($registry),
            $this->createStub(PositionRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new FillCostLedgerIngestionService($ledgerRepository, $lineage),
        );

        $this->expectException(FillCostLedgerIngestionConflict::class);

        $store->project(new ExchangeFillReceived(new ExchangeFillDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: '',
            clientOrderId: null,
            fillId: 'fill-conflict',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            quantity: 1.0,
            price: 100.0,
            fee: 0.01,
            feeCurrency: 'USDT',
            filledAt: new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
        )));
    }

    private function store(
        FuturesOrderSyncService $orderSync,
        FillCostLedgerEntryRepository $ledgerRepository,
    ): DoctrineExchangeLocalProjectionStore {
        $registry = $this->createStub(ManagerRegistry::class);
        $lineage = new TradeLineageManager(
            new TradeLineageRepository($registry),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );

        return new DoctrineExchangeLocalProjectionStore(
            $orderSync,
            new FuturesOrderRepository($registry),
            $this->createStub(PositionRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new FillCostLedgerIngestionService($ledgerRepository, $lineage),
        );
    }
}
