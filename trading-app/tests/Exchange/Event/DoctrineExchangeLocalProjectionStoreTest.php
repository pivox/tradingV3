<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Event;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\FillCostLedgerEntry;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Event\DoctrineExchangeLocalProjectionStore;
use App\Exchange\Event\ExchangeFillReceived;
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
}
