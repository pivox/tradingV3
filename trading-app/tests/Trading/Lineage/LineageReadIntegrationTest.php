<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Entity\OrderIntent;
use App\Entity\TradeLifecycleEvent;
use App\Entity\TradeLineage;
use App\Repository\OrderIntentRepository;
use App\Repository\TradeLifecycleEventRepository;
use App\Repository\TradeLineageRepository;
use App\Trading\Lineage\ReadModel\DoctrineLineageReadStore;
use App\Trading\Lineage\ReadModel\LineageReadCriteria;
use App\Trading\Lineage\ReadModel\LineageReadException;
use App\Trading\Lineage\ReadModel\LineageReadService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DoctrineLineageReadStore::class)]
final class LineageReadIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LineageReadService $service;

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::$kernel->getContainer()->get('doctrine.orm.entity_manager');

        $tool = new SchemaTool($this->em);
        $classes = [
            $this->em->getClassMetadata(OrderIntent::class),
            $this->em->getClassMetadata(TradeLineage::class),
            $this->em->getClassMetadata(TradeLifecycleEvent::class),
        ];
        $tool->dropSchema($classes);
        $tool->createSchema($classes);

        /** @var TradeLineageRepository $lineages */
        $lineages = $this->em->getRepository(TradeLineage::class);
        /** @var OrderIntentRepository $orderIntents */
        $orderIntents = $this->em->getRepository(OrderIntent::class);
        /** @var TradeLifecycleEventRepository $events */
        $events = $this->em->getRepository(TradeLifecycleEvent::class);
        $store = new DoctrineLineageReadStore($lineages, $orderIntents, $events);
        $this->service = new LineageReadService($store);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $tool = new SchemaTool($this->em);
            $tool->dropSchema([
                $this->em->getClassMetadata(OrderIntent::class),
                $this->em->getClassMetadata(TradeLineage::class),
                $this->em->getClassMetadata(TradeLifecycleEvent::class),
            ]);
            $this->em->close();
        }

        parent::tearDown();
    }

    public function testPositionIdCanBeReusedAcrossVenuesWhenVenueIsExplicit(): void
    {
        $this->persistLineage('trade-bitmart', 'bitmart', 'perpetual', 'EX-SHARED', 'POS-SHARED');
        $this->persistLineage('trade-okx', 'okx', 'perpetual', 'EX-SHARED', 'POS-SHARED');

        $page = $this->service->search(
            LineageReadCriteria::forVenueIdentifier('position_id', 'POS-SHARED', 'okx', 'perpetual', 10, 0),
        );

        self::assertSame(1, $page->total);
        self::assertSame('trade-okx', $page->items[0]['lineage']['internal_trade_id']);
        self::assertSame('okx', $page->items[0]['lineage']['exchange']);
    }

    public function testExchangeOrderIdCanBeReusedAcrossVenuesWhenVenueIsExplicit(): void
    {
        $this->persistLineage('trade-bitmart', 'bitmart', 'perpetual', 'EX-SHARED', 'POS-BM');
        $this->persistLineage('trade-okx', 'okx', 'perpetual', 'EX-SHARED', 'POS-OKX');

        $page = $this->service->search(
            LineageReadCriteria::forVenueIdentifier('exchange_order_id', 'EX-SHARED', 'bitmart', 'perpetual', 10, 0),
        );

        self::assertSame(1, $page->total);
        self::assertSame('trade-bitmart', $page->items[0]['lineage']['internal_trade_id']);
        self::assertSame('bitmart', $page->items[0]['lineage']['exchange']);
    }

    public function testSameVenueDuplicateExchangeIdentifierReturnsConflict(): void
    {
        $this->persistLineage('trade-a', 'bitmart', 'perpetual', 'EX-DUP', 'POS-A');
        $this->persistLineage('trade-b', 'bitmart', 'perpetual', 'EX-DUP', 'POS-B');

        $this->expectException(LineageReadException::class);
        $this->expectExceptionCode(409);

        $this->service->search(
            LineageReadCriteria::forVenueIdentifier('exchange_order_id', 'EX-DUP', 'bitmart', 'perpetual', 10, 0),
        );
    }

    public function testLineageDetailDoesNotMixLifecycleEventsFromAmbiguousVenueIdentifiers(): void
    {
        $intentA = $this->persistIntent('trade-a');
        $intentB = $this->persistIntent('trade-b');

        $this->persistLineage('trade-a', 'bitmart', 'perpetual', 'EX-SAME', 'POS-SAME', orderIntent: $intentA, withCloseEvent: false);
        $this->persistLifecycleEvent('trade-a', 'bitmart', 'perpetual', 'EX-SAME', 'POS-SAME', 'order_submitted');

        $this->persistLineage('trade-b', 'bitmart', 'perpetual', 'EX-SAME', 'POS-SAME', orderIntent: $intentB, withCloseEvent: false);
        $this->persistLifecycleEvent('trade-b', 'bitmart', 'perpetual', 'EX-SAME', 'POS-SAME', 'position_closed');

        $page = $this->service->search(
            LineageReadCriteria::forIdentifier('internal_trade_id', 'trade-a', 10, 0),
        );

        self::assertSame(1, $page->total);
        self::assertSame('missing_close_event', $page->items[0]['completeness_status']);
        self::assertSame(['order_submitted'], array_column($page->items[0]['lifecycle_events'], 'event_type'));
        self::assertSame(['trade-a'], array_unique(array_column($page->items[0]['lifecycle_events'], 'internal_trade_id')));
    }

    public function testLineageDetailDoesNotImportConflictingLegacyLifecycleEvents(): void
    {
        $intentA = $this->persistIntent('trade-a');
        $intentB = $this->persistIntent('trade-b');

        $this->persistLineage('trade-a', 'bitmart', 'perpetual', 'EX-SAME', 'POS-SAME', orderIntent: $intentA, withCloseEvent: false);
        $this->persistLifecycleEvent('trade-a', 'bitmart', 'perpetual', 'EX-SAME', 'POS-SAME', 'order_submitted');

        $this->persistLineage('trade-b', 'bitmart', 'perpetual', 'EX-SAME', 'POS-SAME', orderIntent: $intentB, withCloseEvent: false);
        $this->persistLegacyLifecycleEvent('client-trade-b', 'bitmart', 'perpetual', 'EX-SAME', 'POS-OTHER', 'position_closed');

        $page = $this->service->search(
            LineageReadCriteria::forIdentifier('internal_trade_id', 'trade-a', 10, 0),
        );

        self::assertSame(1, $page->total);
        self::assertSame('missing_close_event', $page->items[0]['completeness_status']);
        self::assertSame(['order_submitted'], array_column($page->items[0]['lifecycle_events'], 'event_type'));
    }

    public function testLineageDetailIncludesLegacyCloseMatchedByPositionWithDistinctClosingOrder(): void
    {
        $intent = $this->persistIntent('trade-position-close');

        $this->persistLineage(
            'trade-position-close',
            'bitmart',
            'perpetual',
            'EX-ENTRY',
            'POS-STABLE',
            orderIntent: $intent,
            withCloseEvent: false,
        );
        $this->persistLifecycleEvent('trade-position-close', 'bitmart', 'perpetual', 'EX-ENTRY', 'POS-STABLE', 'order_submitted');
        $this->persistLegacyLifecycleEvent('client-close-order', 'bitmart', 'perpetual', 'EX-CLOSE', 'POS-STABLE', 'position_closed');

        $page = $this->service->search(
            LineageReadCriteria::forIdentifier('internal_trade_id', 'trade-position-close', 10, 0),
        );

        self::assertSame(1, $page->total);
        self::assertSame('complete', $page->items[0]['completeness_status']);
        self::assertSame(['order_submitted', 'position_closed'], array_column($page->items[0]['lifecycle_events'], 'event_type'));
    }

    public function testLineageDetailMatchesLegacyExchangeOrderWhenClientOrderCaseDiffers(): void
    {
        $intent = $this->persistIntent('trade-case');

        $this->persistLineage(
            'trade-case',
            'bitmart',
            'perpetual',
            'EX-CASE',
            'POS-CASE',
            orderIntent: $intent,
            withCloseEvent: false,
        );
        $this->persistLegacyLifecycleEvent('CLIENT-TRADE-CASE', 'bitmart', 'perpetual', 'EX-CASE', null, 'position_closed');

        $page = $this->service->search(
            LineageReadCriteria::forIdentifier('internal_trade_id', 'trade-case', 10, 0),
        );

        self::assertSame(1, $page->total);
        self::assertSame('complete', $page->items[0]['completeness_status']);
        self::assertSame(['position_closed'], array_column($page->items[0]['lifecycle_events'], 'event_type'));
    }

    public function testRunSearchIsPaginatedAndDeterministicallyOrdered(): void
    {
        $this->persistLineage('trade-1', 'bitmart', 'perpetual', 'EX-1', 'POS-1', 'run-paged');
        $this->persistLineage('trade-2', 'bitmart', 'perpetual', 'EX-2', 'POS-2', 'run-paged');
        $this->persistLineage('trade-3', 'bitmart', 'perpetual', 'EX-3', 'POS-3', 'run-paged');

        $page = $this->service->search(
            LineageReadCriteria::forIdentifier('orchestration_run_id', 'run-paged', 2, 0),
        );

        self::assertSame(3, $page->total);
        self::assertSame(2, $page->limit);
        self::assertSame(0, $page->offset);
        self::assertTrue($page->hasMore);
        self::assertSame(['trade-1', 'trade-2'], array_column(array_column($page->items, 'lineage'), 'internal_trade_id'));
    }

    public function testSearchBySetAndOrderIntentId(): void
    {
        $intent = $this->persistIntent('trade-intent');
        $this->persistLineage('trade-intent', 'bitmart', 'perpetual', 'EX-INTENT', 'POS-INTENT', 'run-intent', 'set-a', $intent);
        $this->persistLineage('trade-set-peer', 'bitmart', 'perpetual', 'EX-PEER', 'POS-PEER', 'run-intent', 'set-a');
        $this->persistLineage('trade-other-set', 'bitmart', 'perpetual', 'EX-OTHER', 'POS-OTHER', 'run-intent', 'set-b');

        $setPage = $this->service->search(
            LineageReadCriteria::forIdentifier('orchestration_set_id', 'set-a', 10, 0),
        );
        self::assertSame(2, $setPage->total);
        self::assertSame(['trade-intent', 'trade-set-peer'], array_column(array_column($setPage->items, 'lineage'), 'internal_trade_id'));

        self::assertNotNull($intent->getId());
        $intentPage = $this->service->search(
            LineageReadCriteria::forIdentifier('order_intent_id', (string) $intent->getId(), 10, 0),
        );
        self::assertSame(1, $intentPage->total);
        self::assertSame('trade-intent', $intentPage->items[0]['lineage']['internal_trade_id']);
        self::assertSame($intent->getId(), $intentPage->items[0]['order_intent']['id']);
    }

    private function persistLineage(
        string $internalTradeId,
        string $exchange,
        string $marketType,
        string $exchangeOrderId,
        string $positionId,
        string $orchestrationRunId = 'run-1',
        string $orchestrationSetId = 'set-1',
        ?OrderIntent $orderIntent = null,
        bool $withCloseEvent = true,
    ): void {
        $lineage = (new TradeLineage($internalTradeId, 'client-' . $internalTradeId, 'BTCUSDT'))
            ->setOrderIntent($orderIntent)
            ->setExchange($exchange)
            ->setMarketType($marketType)
            ->setExchangeOrderId($exchangeOrderId)
            ->setPositionId($positionId)
            ->setOrigin('orchestrator')
            ->setRunId($orchestrationRunId)
            ->setCorrelationRunId($orchestrationRunId)
            ->setOrchestrationRunId($orchestrationRunId)
            ->setOrchestrationSetId($orchestrationSetId)
            ->setOrchestrationDashboardId('dash-1');

        $this->em->persist($lineage);
        if ($withCloseEvent) {
            $this->em->persist($this->lifecycleEvent(
                $internalTradeId,
                $exchange,
                $marketType,
                $exchangeOrderId,
                $positionId,
                'position_closed',
                $orchestrationRunId,
                $orchestrationSetId,
            ));
        }
        $this->em->flush();
    }

    private function persistLifecycleEvent(
        string $internalTradeId,
        string $exchange,
        string $marketType,
        string $exchangeOrderId,
        string $positionId,
        string $eventType,
        string $orchestrationRunId = 'run-1',
        string $orchestrationSetId = 'set-1',
    ): void {
        $this->em->persist($this->lifecycleEvent(
            $internalTradeId,
            $exchange,
            $marketType,
            $exchangeOrderId,
            $positionId,
            $eventType,
            $orchestrationRunId,
            $orchestrationSetId,
        ));
        $this->em->flush();
    }

    private function persistLegacyLifecycleEvent(
        string $clientOrderId,
        string $exchange,
        string $marketType,
        string $exchangeOrderId,
        ?string $positionId,
        string $eventType,
        string $orchestrationRunId = 'run-1',
        string $orchestrationSetId = 'set-1',
    ): void {
        $event = $this->lifecycleEvent(
            null,
            $exchange,
            $marketType,
            $exchangeOrderId,
            $positionId,
            $eventType,
            $orchestrationRunId,
            $orchestrationSetId,
        );
        $event->setClientOrderId($clientOrderId);

        $this->em->persist($event);
        $this->em->flush();
    }

    private function lifecycleEvent(
        ?string $internalTradeId,
        string $exchange,
        string $marketType,
        string $exchangeOrderId,
        ?string $positionId,
        string $eventType,
        string $orchestrationRunId,
        string $orchestrationSetId,
    ): TradeLifecycleEvent {
        return (new TradeLifecycleEvent('BTCUSDT', $eventType))
            ->setExchange($exchange)
            ->setMarketType($marketType)
            ->setInternalTradeId($internalTradeId)
            ->setOrderId($exchangeOrderId)
            ->setPositionId($positionId)
            ->setOrchestrationRunId($orchestrationRunId)
            ->setOrchestrationSetId($orchestrationSetId)
            ->setOrchestrationDashboardId('dash-1');
    }

    private function persistIntent(string $internalTradeId): OrderIntent
    {
        $intent = (new OrderIntent())
            ->setExchange('bitmart')
            ->setMarketType('perpetual')
            ->setSymbol('BTCUSDT')
            ->setSide(1)
            ->setType(OrderIntent::TYPE_LIMIT)
            ->setOpenType(OrderIntent::OPEN_TYPE_ISOLATED)
            ->setPositionMode(OrderIntent::POSITION_MODE_ONE_WAY)
            ->setSize(1)
            ->setClientOrderId('client-' . $internalTradeId)
            ->setPresetMode(OrderIntent::PRESET_MODE_NONE)
            ->setInternalTradeId($internalTradeId);

        $this->em->persist($intent);
        $this->em->flush();

        return $intent;
    }
}
