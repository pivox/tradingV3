<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\Timeframe;
use App\Entity\FuturesOrder;
use App\Entity\IndicatorSnapshot;
use App\Entity\MtfState;
use App\Entity\OrderIntent;
use App\Entity\OrderProtection;
use App\Entity\Position;
use App\Provider\Context\ExchangeContext;
use App\Provider\Entity\Contract;
use App\Provider\Entity\Kline;
use App\Provider\Repository\ContractRepository;
use App\Provider\Repository\KlineRepository;
use App\Repository\IndicatorSnapshotRepository;
use App\Repository\MtfStateRepository;
use App\Repository\OrderIntentRepository;
use App\Repository\PositionRepository;
use App\Trading\Storage\FuturesOrderOrderStateRepository;
use App\Trading\Storage\PositionPositionStateRepository;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversNothing]
final class ExchangeScopedStorageTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::$kernel->getContainer()->get('doctrine.orm.entity_manager');

        $metadata = array_map(
            fn (string $class) => $this->em->getClassMetadata($class),
            [
                Contract::class,
                FuturesOrder::class,
                Kline::class,
                Position::class,
                OrderIntent::class,
                OrderProtection::class,
                IndicatorSnapshot::class,
                MtfState::class,
            ],
        );

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $metadata = array_map(
                fn (string $class) => $this->em->getClassMetadata($class),
                [
                    Contract::class,
                    FuturesOrder::class,
                    Kline::class,
                    Position::class,
                    OrderIntent::class,
                    OrderProtection::class,
                    IndicatorSnapshot::class,
                    MtfState::class,
                ],
            );
            (new SchemaTool($this->em))->dropSchema($metadata);
            $this->em->close();
        }

        parent::tearDown();
    }

    public function testContractRepositoryDefaultsToLegacyContextAndCanSelectAnotherExchange(): void
    {
        $bitmart = (new Contract())
            ->setExchange(Exchange::BITMART)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setName('Bitmart BTC');

        $binance = (new Contract())
            ->setExchange(Exchange::BINANCE)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setName('Binance BTC');

        $this->em->persist($bitmart);
        $this->em->persist($binance);
        $this->em->flush();

        /** @var ContractRepository $repository */
        $repository = $this->em->getRepository(Contract::class);

        self::assertSame('Bitmart BTC', $repository->findBySymbol('BTCUSDT')?->getName());
        self::assertSame(
            'Binance BTC',
            $repository->findBySymbol(
                'BTCUSDT',
                new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL),
            )?->getName(),
        );
    }

    public function testKlineRepositorySeparatesSameSymbolTimeframeAndOpenTime(): void
    {
        $openTime = new \DateTimeImmutable('2026-05-31 00:00:00', new \DateTimeZone('UTC'));

        $this->em->persist($this->newKline('bitmart', '100', $openTime));
        $this->em->persist($this->newKline('binance', '200', $openTime));
        $this->em->flush();

        /** @var KlineRepository $repository */
        $repository = $this->em->getRepository(Kline::class);

        self::assertSame('100.000000000000', $repository->getKlines('BTCUSDT', Timeframe::TF_1M, 1)[0]->getClosePrice()->__toString());
        self::assertSame(
            '200.000000000000',
            $repository->getKlines(
                'BTCUSDT',
                Timeframe::TF_1M,
                1,
                new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL),
            )[0]->getClosePrice()->__toString(),
        );
    }

    public function testIndicatorSnapshotUpsertUsesExchangeAndMarketType(): void
    {
        $klineTime = new \DateTimeImmutable('2026-05-31 00:00:00', new \DateTimeZone('UTC'));

        /** @var IndicatorSnapshotRepository $repository */
        $repository = $this->em->getRepository(IndicatorSnapshot::class);

        $repository->upsert($this->newSnapshot('bitmart', $klineTime, ['rsi' => 51]));
        $repository->upsert(
            $this->newSnapshot('binance', $klineTime, ['rsi' => 61]),
            new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL),
        );
        $repository->upsert($this->newSnapshot('bitmart', $klineTime, ['rsi' => 52]));

        self::assertSame(52, $repository->findLastBySymbolAndTimeframe('BTCUSDT', Timeframe::TF_1M)?->getValue('rsi'));
        self::assertSame(
            61,
            $repository->findLastBySymbolAndTimeframe(
                'BTCUSDT',
                Timeframe::TF_1M,
                new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL),
            )?->getValue('rsi'),
        );
    }

    public function testPositionAndOrderIntentRepositoriesFallbackToBitmartOnly(): void
    {
        $this->em->persist((new Position('BTCUSDT', 'LONG'))->setSize('1'));
        $this->em->persist((new Position('BTCUSDT', 'LONG', Exchange::BINANCE, MarketType::PERPETUAL))->setSize('2'));

        $bitmartIntent = $this->newIntent('bitmart', 'shared-client');
        $binanceIntent = $this->newIntent('binance', 'shared-client');
        $this->em->persist($bitmartIntent);
        $this->em->persist($binanceIntent);
        $this->em->flush();

        /** @var PositionRepository $positionRepository */
        $positionRepository = $this->em->getRepository(Position::class);
        /** @var OrderIntentRepository $intentRepository */
        $intentRepository = $this->em->getRepository(OrderIntent::class);
        $binanceContext = new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL);

        self::assertSame('1', $positionRepository->findOneBySymbolSide('BTCUSDT', 'LONG')?->getSize());
        self::assertSame('2', $positionRepository->findOneBySymbolSide('BTCUSDT', 'LONG', $binanceContext)?->getSize());
        self::assertSame('bitmart', $intentRepository->findOneByClientOrderId('shared-client')?->getExchange());
        self::assertSame('binance', $intentRepository->findOneByClientOrderId('shared-client', $binanceContext)?->getExchange());
    }

    public function testTradingStateReadsCanSelectExplicitExchangeContext(): void
    {
        $this->em->persist(
            (new Position('ETHUSDT', 'LONG'))
                ->setSize('1')
                ->setAvgEntryPrice('100')
                ->setUnrealizedPnl('0')
        );
        $this->em->persist(
            (new Position('ETHUSDT', 'LONG', Exchange::BINANCE, MarketType::PERPETUAL))
                ->setSize('2')
                ->setAvgEntryPrice('200')
                ->setUnrealizedPnl('0')
                ->mergePayload(['exchange' => 'binance', 'market_type' => 'perpetual'])
        );
        $this->em->persist($this->newFuturesOrder('bitmart', 'shared-order', '1'));
        $this->em->persist($this->newFuturesOrder('binance', 'shared-order', '2'));
        $this->em->flush();

        $binanceContext = new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL);
        $positionState = new PositionPositionStateRepository(
            $this->em->getRepository(Position::class),
            $this->em,
        );
        $orderState = new FuturesOrderOrderStateRepository(
            $this->em->getRepository(FuturesOrder::class),
            $this->em,
        );

        self::assertSame('1', $positionState->findLocalOpenPosition('ETHUSDT', 'LONG')?->size->__toString());
        self::assertSame('2', $positionState->findLocalOpenPosition('ETHUSDT', 'LONG', $binanceContext)?->size->__toString());
        self::assertCount(1, $positionState->findLocalOpenPositions(['ETHUSDT'], $binanceContext));

        self::assertSame('1', $orderState->findLocalOrder('ETHUSDT', 'shared-order')?->quantity->__toString());
        self::assertSame('2', $orderState->findLocalOrder('ETHUSDT', 'shared-order', $binanceContext)?->quantity->__toString());
        self::assertCount(1, $orderState->findLocalOpenOrders(['ETHUSDT'], $binanceContext));
    }

    public function testMtfStateRepositoryScopesStateByExchangeAndMarketType(): void
    {
        /** @var MtfStateRepository $repository */
        $repository = $this->em->getRepository(MtfState::class);
        $binanceContext = new ExchangeContext(Exchange::BINANCE, MarketType::PERPETUAL);

        $bitmart = $repository->getOrCreateForSymbol('BTCUSDT');
        $bitmart->set4hSide('long');

        $binance = $repository->getOrCreateForSymbol('BTCUSDT', $binanceContext);
        $binance->set4hSide('short');
        $this->em->flush();

        self::assertNotSame($bitmart->getId(), $binance->getId());
        self::assertSame('bitmart', $repository->getOrCreateForSymbol('BTCUSDT')->getExchange());
        self::assertSame('long', $repository->getOrCreateForSymbol('BTCUSDT')->get4hSide());
        self::assertSame('binance', $repository->getOrCreateForSymbol('BTCUSDT', $binanceContext)->getExchange());
        self::assertSame('short', $repository->getOrCreateForSymbol('BTCUSDT', $binanceContext)->get4hSide());
    }

    private function newKline(string $exchange, string $close, \DateTimeImmutable $openTime): Kline
    {
        return (new Kline())
            ->setExchange($exchange)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setTimeframe(Timeframe::TF_1M)
            ->setOpenTime($openTime)
            ->setOpenPrice(BigDecimal::of('100'))
            ->setHighPrice(BigDecimal::of('210'))
            ->setLowPrice(BigDecimal::of('90'))
            ->setClosePrice(BigDecimal::of($close))
            ->setVolume(BigDecimal::of('1'));
    }

    /**
     * @param array<string,mixed> $values
     */
    private function newSnapshot(string $exchange, \DateTimeImmutable $klineTime, array $values): IndicatorSnapshot
    {
        return (new IndicatorSnapshot())
            ->setExchange($exchange)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setTimeframe(Timeframe::TF_1M)
            ->setKlineTime($klineTime)
            ->setValues($values);
    }

    private function newIntent(string $exchange, string $clientOrderId): OrderIntent
    {
        return (new OrderIntent())
            ->setExchange($exchange)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setSide(1)
            ->setType(OrderIntent::TYPE_LIMIT)
            ->setOpenType(OrderIntent::OPEN_TYPE_ISOLATED)
            ->setPositionMode(OrderIntent::POSITION_MODE_ONE_WAY)
            ->setPrice('100')
            ->setSize(1)
            ->setClientOrderId($clientOrderId)
            ->setPresetMode(OrderIntent::PRESET_MODE_NONE)
            ->setQuantization([])
            ->setStatus(OrderIntent::STATUS_DRAFT);
    }

    private function newFuturesOrder(string $exchange, string $orderId, string $size): FuturesOrder
    {
        return (new FuturesOrder())
            ->setExchange($exchange)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('ETHUSDT')
            ->setOrderId($orderId)
            ->setClientOrderId($exchange . '-client')
            ->setStatus('new')
            ->setSide(1)
            ->setType('market')
            ->setPrice('100')
            ->setSize((int) $size)
            ->setFilledSize(0)
            ->setRawData(['exchange' => $exchange, 'market_type' => 'perpetual']);
    }
}
