<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Entity\OrderIntent;
use App\Entity\TradeLineage;
use App\Repository\OrderIntentRepository;
use App\Repository\TradeLineageRepository;
use App\Service\OrderIntentManager;
use App\Logging\Dto\LifecycleContextBuilder;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Execution\ExchangeExecutionService;
use App\TradeEntry\Execution\ExecutionBox;
use App\TradeEntry\Idempotency\DecisionKeyFactory;
use App\TradeEntry\Workflow\ExecuteOrderPlan;
use App\Trading\Lineage\TradeLineageManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(ExecuteOrderPlan::class)]
final class ExecuteOrderPlanLineageSyncTest extends KernelTestCase
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

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema([
            $this->em->getClassMetadata(OrderIntent::class),
            $this->em->getClassMetadata(TradeLineage::class),
        ]);
        $schemaTool->createSchema([
            $this->em->getClassMetadata(OrderIntent::class),
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            (new SchemaTool($this->em))->dropSchema([
                $this->em->getClassMetadata(OrderIntent::class),
            ]);
            $this->em->close();
        }

        parent::tearDown();
    }

    public function testIntentStatusSyncContinuesWhenLineageTableIsMissing(): void
    {
        $intent = $this->persistReadyIntent();
        $result = new ExecutionResult(
            clientOrderId: $intent->getClientOrderId(),
            exchangeOrderId: 'exchange-accepted-1',
            status: ExecutionResult::STATUS_SUBMITTED,
        );

        $workflow = new ExecuteOrderPlan(
            $this->uninitialized(ExecutionBox::class),
            $this->uninitialized(ExchangeExecutionService::class),
            new NullLogger(),
            $this->orderIntentManager(),
            null,
            $this->tradeLineageManager(),
        );
        $method = new \ReflectionMethod(ExecuteOrderPlan::class, 'syncIntentAfterExecution');
        $method->invoke($workflow, $intent, $result);

        self::assertSame(OrderIntent::STATUS_SENT, $intent->getStatus());
        self::assertSame('exchange-accepted-1', $intent->getExchangeOrderId());
    }

    public function testPreSubmitLineageSyncIsBestEffortWhenLineageTableIsMissing(): void
    {
        $intent = $this->persistReadyIntent();
        $contextBuilder = (new LifecycleContextBuilder('BTCUSDT'))
            ->withInternalTradeId('itd-from-mtf')
            ->withTradeId('itd-from-mtf');

        $workflow = new ExecuteOrderPlan(
            $this->uninitialized(ExecutionBox::class),
            $this->uninitialized(ExchangeExecutionService::class),
            new NullLogger(),
            $this->orderIntentManager(),
            null,
            $this->tradeLineageManager(),
        );
        $method = new \ReflectionMethod(ExecuteOrderPlan::class, 'syncLineageBeforeExecution');
        $method->invoke($workflow, $intent, $contextBuilder);

        self::assertSame('itd-from-mtf', $contextBuilder->toArray()['internal_trade_id'] ?? null);
    }

    public function testPreSubmitLineageSyncNormalizesOverlongOrchestrationIds(): void
    {
        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema([
            $this->em->getClassMetadata(OrderIntent::class),
            $this->em->getClassMetadata(TradeLineage::class),
        ]);
        $schemaTool->createSchema([
            $this->em->getClassMetadata(OrderIntent::class),
            $this->em->getClassMetadata(TradeLineage::class),
        ]);

        $intent = $this->persistReadyIntent();
        $contextBuilder = (new LifecycleContextBuilder('BTCUSDT'))
            ->withInternalTradeId('itd-from-mtf')
            ->withTradeId('itd-from-mtf')
            ->merge([
                'orchestration_run_id' => str_repeat('r', 140),
                'orchestration_set_id' => str_repeat('s', 140),
                'orchestration_dashboard_id' => str_repeat('d', 140),
            ]);

        $workflow = new ExecuteOrderPlan(
            $this->uninitialized(ExecutionBox::class),
            $this->uninitialized(ExchangeExecutionService::class),
            new NullLogger(),
            $this->orderIntentManager(),
            null,
            $this->tradeLineageManager(),
        );
        $method = new \ReflectionMethod(ExecuteOrderPlan::class, 'syncLineageBeforeExecution');
        $method->invoke($workflow, $intent, $contextBuilder);

        /** @var TradeLineage $lineage */
        $lineage = $this->em->getRepository(TradeLineage::class)->findOneBy([
            'internalTradeId' => 'itd-from-mtf',
        ]);

        self::assertNotNull($lineage);
        self::assertSame(140, strlen($lineage->getOrchestrationRunId() ?? ''));
        self::assertLessThanOrEqual(96, strlen($lineage->getOrchestrationSetId() ?? ''));
        self::assertLessThanOrEqual(96, strlen($lineage->getOrchestrationDashboardId() ?? ''));
    }

    private function persistReadyIntent(): OrderIntent
    {
        $intent = (new OrderIntent())
            ->setExchange(Exchange::BITMART)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setSide(1)
            ->setType(OrderIntent::TYPE_LIMIT)
            ->setOpenType(OrderIntent::OPEN_TYPE_ISOLATED)
            ->setPositionMode(OrderIntent::POSITION_MODE_ONE_WAY)
            ->setSize(1)
            ->setClientOrderId('cid-lineage-missing')
            ->setPresetMode(OrderIntent::PRESET_MODE_NONE)
            ->setDecisionKey('bitmart:perpetual:BTCUSDT:1m:1764161200:long:scalper:v1')
            ->markAsReadyToSend();

        $this->em->persist($intent);
        $this->em->flush();

        return $intent;
    }

    private function orderIntentManager(): OrderIntentManager
    {
        /** @var OrderIntentRepository $repository */
        $repository = $this->em->getRepository(OrderIntent::class);

        return new OrderIntentManager($repository, $this->em, new NullLogger(), new DecisionKeyFactory());
    }

    private function tradeLineageManager(): TradeLineageManager
    {
        /** @var TradeLineageRepository $repository */
        $repository = $this->em->getRepository(TradeLineage::class);

        return new TradeLineageManager($repository, $this->em, new NullLogger());
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    private function uninitialized(string $className): object
    {
        return (new \ReflectionClass($className))->newInstanceWithoutConstructor();
    }
}
