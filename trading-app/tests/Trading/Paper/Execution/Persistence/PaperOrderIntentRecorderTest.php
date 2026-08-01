<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Persistence;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\OrderIntent;
use App\Entity\OrderProtection;
use App\Entity\TradeLineage;
use App\Logging\Dto\LifecycleContextBuilder;
use App\Provider\Context\ExchangeContext;
use App\Repository\OrderIntentRepository;
use App\Repository\TradeLineageRepository;
use App\Service\OrderIntentManager;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Dto\PreparedTradeEntry;
use App\TradeEntry\Idempotency\DecisionKeyFactory;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Types\Side;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Persistence\PaperOrderIntentRecorder;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(PaperOrderIntentRecorder::class)]
final class PaperOrderIntentRecorderTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<class-string> */
    private array $entities = [OrderIntent::class, OrderProtection::class, TradeLineage::class];

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $metadata = array_map(fn (string $class) => $this->em->getClassMetadata($class), $this->entities);
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $metadata = array_map(fn (string $class) => $this->em->getClassMetadata($class), $this->entities);
            (new SchemaTool($this->em))->dropSchema($metadata);
            $this->em->close();
        }
        parent::tearDown();
    }

    public function testReserveAndAcknowledgePersistExactPaperJoin(): void
    {
        /** @var OrderIntentRepository $intentRepository */
        $intentRepository = $this->em->getRepository(OrderIntent::class);
        /** @var TradeLineageRepository $lineageRepository */
        $lineageRepository = $this->em->getRepository(TradeLineage::class);
        $intents = new OrderIntentManager($intentRepository, $this->em, new NullLogger(), new DecisionKeyFactory());
        $lineages = new TradeLineageManager($lineageRepository, $this->em, new NullLogger());
        $recorder = new PaperOrderIntentRecorder($intents, $lineages);
        $cell = PaperExecutionCell::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'sha256:' . str_repeat('a', 64),
            'scalper_micro',
            'run-001',
        );
        $prepared = new PreparedTradeEntry(
            new OrderPlanModel('BTCUSDT', Side::Long, 'market', 'isolated', 1, 100.0, 98.0, 104.0, 2, 3, 2, 1.0, exchangeContext: new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL)),
            null,
            'paper-decision-1',
            'paper-trade-1',
            new LifecycleContextBuilder('BTCUSDT'),
            'scalper_micro',
            '1m',
        );

        $identity = $recorder->reserve(
            $prepared,
            ['client_order_id' => 'CIDPAPER0001'],
            $cell->provenance(PaperProfileEligibility::REFERENCE_ONLY),
        );
        $recorder->acknowledge($identity, new ExecutionResult('CIDPAPER0001', 'fake-order-1', ExecutionResult::STATUS_SUBMITTED));

        $intent = $intentRepository->find($identity['order_intent_id']);
        self::assertInstanceOf(OrderIntent::class, $intent);
        self::assertSame(OrderIntent::STATUS_SENT, $intent->getStatus());
        self::assertSame('fake', $intent->getExchange());
        self::assertSame('testnet', $intent->getPaperNetwork());
        self::assertSame('hyperliquid', $intent->getMarketDataVenue());
        self::assertSame($cell->id, $intent->getPaperExecutionCellId());
        self::assertSame('reference_only', $intent->getPaperEligibility());

        $lineage = $lineageRepository->findOneByOrderIntentId($identity['order_intent_id']);
        self::assertInstanceOf(TradeLineage::class, $lineage);
        self::assertSame('paper-trade-1', $lineage->getInternalTradeId());
        self::assertSame('fake-order-1', $lineage->getExchangeOrderId());
        self::assertSame($cell->id, $lineage->getPaperExecutionCellId());
    }
}
