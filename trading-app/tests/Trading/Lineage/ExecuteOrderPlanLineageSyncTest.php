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
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
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

    public function testPreSubmitCanonicalIntentMismatchPropagatesBeforeExecution(): void
    {
        [$workflow, $intent, $mismatch] = $this->canonicalConflictFixture();
        $method = new \ReflectionMethod(ExecuteOrderPlan::class, 'syncLineageBeforeExecution');

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_mismatch:intent_id');
        $method->invoke($workflow, $intent, null, $mismatch);
    }

    public function testPostExecutionCanonicalIntentMismatchPropagates(): void
    {
        [$workflow, $intent, $mismatch] = $this->canonicalConflictFixture();
        $method = new \ReflectionMethod(ExecuteOrderPlan::class, 'syncLineageAfterExecution');

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_mismatch:intent_id');
        $method->invoke($workflow, $intent, new ExecutionResult('cid', 'exchange-order', ExecutionResult::STATUS_SUBMITTED), $mismatch);
    }

    public function testIntentStatusSyncDoesNotSwallowCanonicalIntentMismatch(): void
    {
        [$workflow, $intent, $mismatch] = $this->canonicalConflictFixture();
        $method = new \ReflectionMethod(ExecuteOrderPlan::class, 'syncIntentAfterExecution');

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_mismatch:intent_id');
        $method->invoke($workflow, $intent, new ExecutionResult('cid', 'exchange-order', ExecutionResult::STATUS_SUBMITTED), $mismatch);
    }

    public function testSubmittedCanonicalIdentityOverridesConflictingProviderRawIdentity(): void
    {
        $workflow = new ExecuteOrderPlan(
            $this->uninitialized(ExecutionBox::class),
            $this->uninitialized(ExchangeExecutionService::class),
            new NullLogger(),
        );
        $method = new \ReflectionMethod($workflow, 'withSubmittedIdentity');
        $identity = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())
            ->withDecision('018f47a2-4f42-7e1b-8d3a-4dc9571bb11b', 'decision-key')
            ->withIntent('intent-typed');
        $result = new ExecutionResult(
            'cid',
            'exchange-order-typed',
            ExecutionResult::STATUS_SUBMITTED,
            [
                'provider' => 'preserved',
                'canonical_identity' => [
                    'intent_id' => 'intent-from-untrusted-raw',
                    'order_id' => 'exchange-order-from-untrusted-raw',
                ],
            ],
        );

        /** @var ExecutionResult $submitted */
        $submitted = $method->invoke($workflow, $result, $identity);

        self::assertSame('preserved', $submitted->raw['provider'] ?? null);
        self::assertSame('intent-typed', $submitted->raw['canonical_identity']['intent_id'] ?? null);
        self::assertSame('exchange-order-typed', $submitted->raw['canonical_identity']['order_id'] ?? null);
    }

    /** @return array{ExecuteOrderPlan,OrderIntent,LineageContext} */
    private function canonicalConflictFixture(): array
    {
        $schemaTool = new SchemaTool($this->em);
        $metadata = [
            $this->em->getClassMetadata(OrderIntent::class),
            $this->em->getClassMetadata(TradeLineage::class),
        ];
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $baseIdentity = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())
            ->withDecision('018f47a2-4f42-7e1b-8d3a-4dc9571bb11b', 'decision-key');
        $identity = $baseIdentity->withIntent('intent-persisted');
        $intent = (new OrderIntent())
            ->setExchange(Exchange::FAKE)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setSide(1)
            ->setType(OrderIntent::TYPE_LIMIT)
            ->setOpenType(OrderIntent::OPEN_TYPE_ISOLATED)
            ->setPositionMode(OrderIntent::POSITION_MODE_ONE_WAY)
            ->setSize(1)
            ->setClientOrderId('cid-canonical-conflict')
            ->setPresetMode(OrderIntent::PRESET_MODE_NONE)
            ->setDecisionKey('decision-key')
            ->applyLineageContext($identity)
            ->markAsReadyToSend();
        $this->em->persist($intent);
        $this->em->flush();
        $lineages = $this->tradeLineageManager();
        $lineages->ensureForIntent($intent, $identity);

        return [new ExecuteOrderPlan(
            $this->uninitialized(ExecutionBox::class),
            $this->uninitialized(ExchangeExecutionService::class),
            new NullLogger(),
            $this->orderIntentManager(),
            null,
            $lineages,
        ), $intent, $baseIdentity->withIntent('intent-other')];
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
