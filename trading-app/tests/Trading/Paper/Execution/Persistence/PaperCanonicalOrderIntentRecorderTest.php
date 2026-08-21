<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Persistence;

use App\Entity\OrderIntent;
use App\Entity\OrderProtection;
use App\Entity\TradeLineage;
use App\Repository\OrderIntentRepository;
use App\Repository\TradeLineageRepository;
use App\Service\OrderIntentManager;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Idempotency\DecisionKeyFactory;
use App\Tests\Trading\Paper\Execution\Strategy\PaperCanonicalPreparedEffectCodecTest;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Paper\Execution\Persistence\PaperCanonicalOrderIntentRecorder;
use App\Trading\Paper\Execution\Persistence\PaperCanonicalOrderIntentRecorderInterface;
use App\Trading\Paper\Execution\Persistence\PaperExecutionProvenance;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[CoversClass(PaperCanonicalOrderIntentRecorder::class)]
final class PaperCanonicalOrderIntentRecorderTest extends KernelTestCase
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

    public function testReservePersistsExactCanonicalPlanWithFakeExecutionAndMarketVenueLineage(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $recorder = $this->recorder();

        $identity = $recorder->reserve(
            $effect->plan,
            $effect->lineage,
            $effect->decisionKey,
            $effect->executionTimeframe,
            ['client_order_id' => 'CIDPAPERCANONICAL001'],
            $effect->provenance,
        );

        /** @var OrderIntentRepository $intents */
        $intents = $this->em->getRepository(OrderIntent::class);
        $intent = $intents->find($identity['order_intent_id']);
        self::assertInstanceOf(OrderIntent::class, $intent);
        self::assertSame(OrderIntent::STATUS_READY_TO_SEND, $intent->getStatus());
        self::assertSame('fake', $intent->getExchange());
        self::assertSame('hyperliquid', $intent->getMarketDataVenue());
        self::assertSame((string) $effect->plan->quantity, (string) $intent->getSize());
        self::assertSame($effect->plan->planHash, $intent->getRawInputs()['plan_hash'] ?? null);
        self::assertSame($effect->plan->toArray(), $intent->getRawInputs()['plan'] ?? null);
        self::assertSame(65, $this->em->getClassMetadata(OrderIntent::class)->getFieldMapping('price')->precision);
        self::assertSame(30, $this->em->getClassMetadata(OrderIntent::class)->getFieldMapping('price')->scale);
        self::assertSame(65, $this->em->getClassMetadata(OrderProtection::class)->getFieldMapping('price')->precision);
        self::assertSame(30, $this->em->getClassMetadata(OrderProtection::class)->getFieldMapping('price')->scale);
        self::assertArrayNotHasKey(
            'effective_config_snapshot',
            $intent->getRawInputs()['canonical_identity'] ?? [],
        );
        self::assertCount(1 + count($effect->plan->targets), $intent->getProtections());

        $persistedIdentity = $intent->requireExecutionLineageContext();
        self::assertSame('hyperliquid', $persistedIdentity->exchange);
        self::assertSame('CIDPAPERCANONICAL001', $persistedIdentity->clientOrderId);
        self::assertSame($effect->decisionKey, $persistedIdentity->decisionKey);
        self::assertNotNull($persistedIdentity->intentId);

        /** @var TradeLineageRepository $lineages */
        $lineages = $this->em->getRepository(TradeLineage::class);
        $lineage = $lineages->findOneByOrderIntentId($identity['order_intent_id']);
        self::assertInstanceOf(TradeLineage::class, $lineage);
        self::assertSame('fake', $lineage->getExchange());
        self::assertSame('hyperliquid', $lineage->getMarketDataVenue());
        self::assertSame($effect->lineage->modeId, $lineage->getModeId());
        self::assertSame($effect->lineage->setupId, $lineage->getSetupId());
        self::assertSame($effect->provenance['run_id'], $lineage->getRunId());
        self::assertSame($effect->provenance['strategy_profile'], $lineage->getProfile());
        $lifecycle = (new TradeLineageManager($lineages, $this->em, new NullLogger()))
            ->lifecycleExtra($lineage);
        foreach (PaperExecutionProvenance::MODERN_KEYS as $key) {
            self::assertSame($effect->provenance[$key], $lifecycle[$key] ?? null, $key);
        }
    }

    public function testReserveSerializesQuantityIndependentlyFromPhpPrecision(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $expected = (string) CanonicalOrderPlanDecimal::fromFloat(
            $effect->plan->quantity,
            'test_decimal_invalid',
        );
        $previousPrecision = ini_get('precision');
        self::assertIsString($previousPrecision);

        try {
            self::assertNotFalse(ini_set('precision', '2'));
            self::assertNotSame($expected, (string) $effect->plan->quantity);
            $identity = $this->recorder()->reserve(
                $effect->plan,
                $effect->lineage,
                $effect->decisionKey,
                $effect->executionTimeframe,
                ['client_order_id' => 'CIDPAPERCANONICAL004'],
                $effect->provenance,
            );
        } finally {
            ini_set('precision', $previousPrecision);
        }

        $intent = $this->em->getRepository(OrderIntent::class)->find($identity['order_intent_id']);
        self::assertInstanceOf(OrderIntent::class, $intent);
        self::assertSame($expected, (string) $intent->getSize());
    }

    public function testReserveRejectsCrossBoundDecisionBeforePersistence(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();

        try {
            $this->recorder()->reserve(
                $effect->plan,
                $effect->lineage,
                'another-decision',
                $effect->executionTimeframe,
                ['client_order_id' => 'CIDPAPERCANONICAL002'],
                $effect->provenance,
            );
            self::fail('Cross-bound canonical decision was persisted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_canonical_order_intent_invalid', $exception->getMessage());
        }

        self::assertSame(0, $this->em->getRepository(OrderIntent::class)->count([]));
    }

    public function testAcknowledgeUpdatesTheExactFakeIntentAndLineage(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $recorder = $this->recorder();
        $identity = $recorder->reserve(
            $effect->plan,
            $effect->lineage,
            $effect->decisionKey,
            $effect->executionTimeframe,
            ['client_order_id' => 'CIDPAPERCANONICAL003'],
            $effect->provenance,
        );

        $recorder->acknowledge($identity, new ExecutionResult(
            'CIDPAPERCANONICAL003',
            'fake-canonical-order-1',
            ExecutionResult::STATUS_SUBMITTED,
        ));

        $intent = $this->em->getRepository(OrderIntent::class)->find($identity['order_intent_id']);
        self::assertInstanceOf(OrderIntent::class, $intent);
        self::assertSame(OrderIntent::STATUS_SENT, $intent->getStatus());
        self::assertSame('fake-canonical-order-1', $intent->getExchangeOrderId());
        $lineage = $this->em->getRepository(TradeLineage::class)->findOneBy(['orderIntent' => $intent]);
        self::assertInstanceOf(TradeLineage::class, $lineage);
        self::assertSame('fake-canonical-order-1', $lineage->getExchangeOrderId());
    }

    public function testAcknowledgeRejectsCrossBoundExecutionResultBeforeMutation(): void
    {
        $effect = PaperCanonicalPreparedEffectCodecTest::fixture();
        $recorder = $this->recorder();
        $identity = $recorder->reserve(
            $effect->plan,
            $effect->lineage,
            $effect->decisionKey,
            $effect->executionTimeframe,
            ['client_order_id' => 'CIDPAPERCANONICAL005'],
            $effect->provenance,
        );

        try {
            $recorder->acknowledge($identity, new ExecutionResult(
                'ANOTHERCLIENTORDER',
                'cross-bound-order',
                ExecutionResult::STATUS_SUBMITTED,
            ));
            self::fail('A cross-bound execution result mutated the canonical intent.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_canonical_order_intent_identity_conflict', $exception->getMessage());
        }

        $intent = $this->em->getRepository(OrderIntent::class)->find($identity['order_intent_id']);
        self::assertInstanceOf(OrderIntent::class, $intent);
        self::assertSame(OrderIntent::STATUS_READY_TO_SEND, $intent->getStatus());
        self::assertNull($intent->getExchangeOrderId());
        $lineage = $this->em->getRepository(TradeLineage::class)->findOneBy(['orderIntent' => $intent]);
        self::assertInstanceOf(TradeLineage::class, $lineage);
        self::assertNull($lineage->getExchangeOrderId());
    }

    public function testRecorderHasNoLegacyPlanDependency(): void
    {
        $path = (new \ReflectionClass(PaperCanonicalOrderIntentRecorder::class))->getFileName();
        self::assertIsString($path);
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringNotContainsString('PreparedTradeEntry', $source);
        self::assertStringNotContainsString('OrderPlanModel', $source);
    }

    public function testCanonicalRecorderServiceIsWiredSeparatelyFromLegacyRecorder(): void
    {
        $attributes = (new \ReflectionClass(PaperCanonicalOrderIntentRecorder::class))
            ->getAttributes(AsAlias::class);

        self::assertCount(1, $attributes);
        self::assertSame(
            PaperCanonicalOrderIntentRecorderInterface::class,
            $attributes[0]->newInstance()->id,
        );
    }

    private function recorder(): PaperCanonicalOrderIntentRecorder
    {
        /** @var OrderIntentRepository $intents */
        $intents = $this->em->getRepository(OrderIntent::class);
        /** @var TradeLineageRepository $lineages */
        $lineages = $this->em->getRepository(TradeLineage::class);

        return new PaperCanonicalOrderIntentRecorder(
            new OrderIntentManager($intents, $this->em, new NullLogger(), new DecisionKeyFactory()),
            new TradeLineageManager($lineages, $this->em, new NullLogger()),
            $this->em,
        );
    }
}
