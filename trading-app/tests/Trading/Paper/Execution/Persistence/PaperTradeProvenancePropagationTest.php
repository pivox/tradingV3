<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Persistence;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\FillCostLedgerEntry;
use App\Entity\OrderIntent;
use App\Entity\TradeLifecycleEvent;
use App\Entity\TradeLineage;
use App\Entity\TradeZoneEvent;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Event\ExchangeFillReceived;
use App\Logging\TradeLifecycleLogger;
use App\Provider\Context\ExchangeContext;
use App\Repository\FillCostLedgerEntryRepository;
use App\Repository\OrderIntentRepository;
use App\Repository\TradeLifecycleEventRepository;
use App\Repository\TradeLineageRepository;
use App\Repository\TradeZoneEventRepository;
use App\Service\OrderIntentManager;
use App\TradeEntry\Dto\ZoneSkipEventDto;
use App\TradeEntry\Idempotency\DecisionKeyFactory;
use App\TradeEntry\Service\ZoneSkipPersistenceService;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Persistence\PaperExecutionProvenance;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Pnl\FillCostLedgerIngestionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PaperExecutionProvenance::class)]
final class PaperTradeProvenancePropagationTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<class-string> */
    private array $entityClasses = [
        OrderIntent::class,
        TradeLineage::class,
        FillCostLedgerEntry::class,
        TradeLifecycleEvent::class,
        TradeZoneEvent::class,
    ];

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::$kernel->getContainer()->get('doctrine.orm.entity_manager');
        $metadata = array_map(fn (string $class) => $this->em->getClassMetadata($class), $this->entityClasses);
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $metadata = array_map(fn (string $class) => $this->em->getClassMetadata($class), $this->entityClasses);
            (new SchemaTool($this->em))->dropSchema($metadata);
            $this->em->close();
        }
        parent::tearDown();
    }

    public function testEveryDurablePaperFactReceivesTheExactCellProvenance(): void
    {
        $provenance = $this->provenance();
        /** @var OrderIntentRepository $intents */
        $intents = $this->em->getRepository(OrderIntent::class);
        $intent = (new OrderIntentManager($intents, $this->em, new NullLogger(), new DecisionKeyFactory()))->createIntent($provenance + [
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'side' => 1,
            'type' => 'limit',
            'open_type' => 'isolated',
            'position_mode' => 'one_way',
            'size' => 1,
            'price' => '100',
            'client_order_id' => 'paper-cid-1',
            'preset_mode' => 'none',
        ]);

        /** @var TradeLineageRepository $lineages */
        $lineages = $this->em->getRepository(TradeLineage::class);
        $lineageManager = new TradeLineageManager($lineages, $this->em, new NullLogger());
        $lineage = $lineageManager->ensureForIntent($intent, $provenance + ['internal_trade_id' => 'paper-trade-1', 'profile' => 'scalper_micro']);

        /** @var FillCostLedgerEntryRepository $ledger */
        $ledger = $this->em->getRepository(FillCostLedgerEntry::class);
        $fill = (new FillCostLedgerIngestionService($ledger, $lineageManager))->ingestExchangeFill(new ExchangeFillReceived(new ExchangeFillDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'fake-order-1',
            clientOrderId: 'paper-cid-1',
            fillId: 'fake-fill-1',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            quantity: 1.0,
            price: 100.0,
            fee: 0.05,
            feeCurrency: 'USDT',
            filledAt: new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            metadata: $provenance + ['internal_trade_id' => 'paper-trade-1'],
        )))->entry;

        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-08-01T10:00:01+00:00');
            }
        };
        (new TradeLifecycleLogger($this->em, $clock))->logOrderSubmitted(
            'BTCUSDT', 'fake-order-1', 'paper-cid-1', 'LONG', '1', '100',
            runId: 'run-001', exchange: 'fake', extra: $lineageManager->lifecycleExtra($lineage), marketType: 'perpetual',
        );
        /** @var TradeLifecycleEventRepository $lifecycles */
        $lifecycles = $this->em->getRepository(TradeLifecycleEvent::class);
        $lifecycle = $lifecycles->findOneBy([]);
        self::assertInstanceOf(TradeLifecycleEvent::class, $lifecycle);

        /** @var TradeZoneEventRepository $zones */
        $zones = $this->em->getRepository(TradeZoneEvent::class);
        (new ZoneSkipPersistenceService($zones))->persist(new ZoneSkipEventDto(
            symbol: 'BTCUSDT',
            happenedAt: new \DateTimeImmutable('2026-08-01T10:00:02+00:00'),
            decisionKey: 'decision-1',
            timeframe: '1m',
            configProfile: 'scalper_micro',
            zoneMin: 99.0,
            zoneMax: 101.0,
            candidatePrice: 102.0,
            zoneDevPct: 0.02,
            zoneMaxDevPct: 0.01,
            mtfContext: $provenance,
            exchangeContext: new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL),
        ));
        $zone = $zones->findOneBy([]);
        self::assertInstanceOf(TradeZoneEvent::class, $zone);

        foreach ([$intent, $lineage, $fill, $lifecycle, $zone] as $fact) {
            self::assertSame('testnet', $fact->getPaperNetwork());
            self::assertSame($provenance['paper_execution_cell_id'], $fact->getPaperExecutionCellId());
            self::assertSame($provenance['configuration_snapshot_id'], $fact->getConfigurationSnapshotId());
            self::assertSame('reference_only', $fact->getPaperEligibility());
            self::assertSame('fake', $fact->getExchange());
            self::assertSame('hyperliquid', $fact->getMarketDataVenue());
        }
    }

    public function testMissingOrConflictingPaperValuesFailClosed(): void
    {
        $provenance = $this->provenance();
        unset($provenance['paper_network']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_execution_provenance_invalid');
        (new OrderIntent())->applyPaperExecutionProvenance($provenance);
    }

    public function testProvenanceThatConflictsWithItsCellIdentityFailsClosed(): void
    {
        $provenance = $this->provenance();
        $provenance['paper_execution_cell_id'] = 'sha256:' . str_repeat('f', 64);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_execution_provenance_invalid');
        (new OrderIntent())->applyPaperExecutionProvenance($provenance);
    }

    /** @return array<string, string> */
    private function provenance(): array
    {
        return PaperExecutionCell::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'sha256:' . str_repeat('a', 64),
            'scalper_micro',
            'run-001',
        )->provenance(PaperProfileEligibility::REFERENCE_ONLY);
    }
}
