<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\PositionSide;
use App\Entity\OrderIntent;
use App\Entity\TradeLifecycleEvent;
use App\Entity\TradeLineage;
use App\Logging\TradeLifecycleLogger;
use App\Repository\TradeLifecycleEventRepository;
use App\Repository\TradeLineageRepository;
use App\Trading\Dto\PositionDto;
use App\Trading\Dto\PositionHistoryEntryDto;
use App\Trading\Event\PositionClosedEvent;
use App\Trading\Event\PositionOpenedEvent;
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\TradeLineageManager;
use App\Trading\Listener\TradeLifecycleLoggerListener;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #132 j (J1b): the position events of a canonical trade carry the structured identity of its
 * order_submitted event, so position_trade_analysis_v2 can classify the trade "canonical". Legacy
 * position events are written as before, and a failure to rebuild the identity never throws.
 */
#[CoversClass(TradeLifecycleLoggerListener::class)]
final class TradeLifecycleLoggerListenerCanonicalIdentityTest extends KernelTestCase
{
    private const DECISION_ID = 'ecdd472b-6a8b-589d-b725-130829338914';

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
        $schemaTool->dropSchema($this->metadata());
        $schemaTool->createSchema($this->metadata());
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            (new SchemaTool($this->em))->dropSchema($this->metadata());
            $this->em->close();
        }

        parent::tearDown();
    }

    public function testCanonicalPositionEventsCarryTheIdentityOfTheirOrderSubmittedEvent(): void
    {
        $lineage = $this->canonicalLineage('ord-canonical');
        $warnings = new CanonicalIdentityWarningLogger();

        $this->openAndClose($this->listener($warnings), $lineage, 'pos-canonical');

        foreach (['position_opened', 'position_closed'] as $eventType) {
            $event = $this->event($eventType, 'pos-canonical');
            self::assertTrue($event->hasCompleteCanonicalIdentity(), $eventType);
            self::assertSame([
                'mode_id' => 'scalping',
                'mode_version' => '1.1.0',
                'setup_id' => 'scalping.trend_continuation.long',
                'setup_version' => '1.1.0',
                'side' => 'LONG',
                'decision_id' => self::DECISION_ID,
                'decision_key' => 'decision-canonical',
                'intent_id' => 'int:canonical',
                'order_id' => 'ord-canonical',
                'position_id' => 'pos-canonical',
                'trade_id' => $lineage->getInternalTradeId(),
                'correlation_run_id' => 'run-canonical',
                'orchestration_run_id' => 'run-canonical',
                'orchestration_set_id' => 'set-canonical',
                'orchestration_dashboard_id' => 'dashboard-canonical',
                'internal_trade_id' => $lineage->getInternalTradeId(),
                'exchange' => 'fake',
                'market_type' => 'perpetual',
            ], self::identity($event), $eventType);
        }
        self::assertSame([], $warnings->records);
    }

    public function testLegacyPositionEventsAreWrittenAsBefore(): void
    {
        $lineage = $this->legacyLineage();
        $warnings = new CanonicalIdentityWarningLogger();

        $this->openAndClose($this->listener($warnings), $lineage, 'pos-legacy');

        foreach (['position_opened', 'position_closed'] as $eventType) {
            $event = $this->event($eventType, 'pos-legacy');
            self::assertFalse($event->hasCompleteCanonicalIdentity(), $eventType);
            self::assertSame([
                'mode_id' => null,
                'mode_version' => null,
                'setup_id' => null,
                'setup_version' => null,
                'side' => 'LONG',
                'decision_id' => null,
                'decision_key' => null,
                'intent_id' => null,
                'order_id' => null,
                'position_id' => 'pos-legacy',
                'trade_id' => null,
                'correlation_run_id' => null,
                'orchestration_run_id' => null,
                'orchestration_set_id' => null,
                'orchestration_dashboard_id' => null,
                'internal_trade_id' => 'itd-legacy',
                'exchange' => 'bitmart',
                'market_type' => 'perpetual',
            ], self::identity($event), $eventType);
            self::assertSame('itd-legacy', $event->getExtra()['internal_trade_id'] ?? null);
        }
        self::assertSame([], $warnings->records);
    }

    public function testACanonicalIdentityThatCannotBeRebuiltKeepsTodaysRowsAndOnlyWarns(): void
    {
        // The intent never learnt its exchange order id: the identity has no order_id.
        $lineage = $this->canonicalLineage(null);
        $warnings = new CanonicalIdentityWarningLogger();

        $this->openAndClose($this->listener($warnings), $lineage, 'pos-unacknowledged');

        foreach (['position_opened', 'position_closed'] as $eventType) {
            $event = $this->event($eventType, 'pos-unacknowledged');
            self::assertFalse($event->hasCompleteCanonicalIdentity(), $eventType);
            $identity = self::identity($event);
            foreach (['mode_id', 'setup_id', 'decision_id', 'decision_key', 'intent_id', 'order_id', 'trade_id'] as $field) {
                self::assertNull($identity[$field], $eventType . ' ' . $field);
            }
            self::assertSame('pos-unacknowledged', $identity['position_id']);
        }
        self::assertSame([
            ['warning', 'trade_lifecycle.canonical_position_identity_unavailable', 'position_opened', 'canonical_identity_missing:order_id'],
            ['warning', 'trade_lifecycle.canonical_position_identity_unavailable', 'position_closed', 'canonical_identity_missing:order_id'],
        ], array_map(
            static fn (array $record): array => [$record[0], $record[1], $record[2]['event_type'] ?? null, $record[2]['reason'] ?? null],
            $warnings->records,
        ));
    }

    public function testACanonicalPositionWithoutPositionIdOnlyWarns(): void
    {
        $lineage = $this->canonicalLineage('ord-canonical');
        $warnings = new CanonicalIdentityWarningLogger();

        $this->listener($warnings)->onPositionClosed(new PositionClosedEvent(
            positionHistory: $this->history([]),
            exchange: Exchange::FAKE->value,
            extra: ['market_type' => MarketType::PERPETUAL->value, 'internal_trade_id' => $lineage->getInternalTradeId()],
        ));

        $events = $this->em->getRepository(TradeLifecycleEvent::class)->findBy(['eventType' => 'position_closed']);
        self::assertCount(1, $events);
        self::assertNull($events[0]->getDecisionId());
        self::assertSame(
            ['canonical_identity_missing:position_id'],
            array_map(static fn (array $record): mixed => $record[2]['reason'] ?? null, $warnings->records),
        );
    }

    private function openAndClose(TradeLifecycleLoggerListener $listener, TradeLineage $lineage, string $positionId): void
    {
        $extra = ['market_type' => MarketType::PERPETUAL->value, 'internal_trade_id' => $lineage->getInternalTradeId()];
        $exchange = $lineage->getExchange();
        $listener->onPositionOpened(new PositionOpenedEvent(
            position: new PositionDto(
                symbol: 'BTCUSDT',
                side: PositionSide::LONG,
                size: BigDecimal::of('1'),
                entryPrice: BigDecimal::of('100'),
                markPrice: BigDecimal::of('100'),
                unrealizedPnl: BigDecimal::zero(),
                leverage: BigDecimal::of('1'),
                openedAt: new \DateTimeImmutable('2026-06-23 10:00:00 UTC'),
                raw: ['position_id' => $positionId],
            ),
            exchange: $exchange,
            extra: $extra,
        ));
        $listener->onPositionClosed(new PositionClosedEvent(
            positionHistory: $this->history(['position_id' => $positionId]),
            exchange: $exchange,
            extra: $extra,
        ));
    }

    /** @param array<string, mixed> $raw */
    private function history(array $raw): PositionHistoryEntryDto
    {
        return new PositionHistoryEntryDto(
            symbol: 'BTCUSDT',
            side: PositionSide::LONG,
            size: BigDecimal::of('1'),
            entryPrice: BigDecimal::of('100'),
            exitPrice: BigDecimal::of('101'),
            realizedPnl: BigDecimal::of('1'),
            fees: null,
            openedAt: new \DateTimeImmutable('2026-06-23 10:00:00 UTC'),
            closedAt: new \DateTimeImmutable('2026-06-23 10:05:00 UTC'),
            raw: $raw,
        );
    }

    private function canonicalLineage(?string $exchangeOrderId): TradeLineage
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', 'scalping.trend_continuation.long', '1.1.0',
            'fake', 'test', 'long', ShadowExecutionCapability::Fake,
        ));
        $identity = LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-canonical',
            'correlation_run_id' => 'run-canonical',
            'orchestration_set_id' => 'set-canonical',
            'orchestration_dashboard_id' => 'dashboard-canonical',
            'mode_id' => 'scalping',
            'mode_version' => '1.1.0',
            'setup_id' => 'scalping.trend_continuation.long',
            'setup_version' => '1.1.0',
            'config_hash' => $snapshot->configHash,
            'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'side' => 'LONG',
            'exchange' => 'fake',
            'environment' => 'test',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'decision_key' => 'decision-canonical',
            'decision_id' => self::DECISION_ID,
            'intent_id' => 'int:canonical',
            'dry_run' => true,
            'effective_config_reference' => 'effective-config:canonical',
            'effective_config_snapshot' => $snapshot->toArray(),
        ]);
        $intent = $this->intent('cid-canonical', Exchange::FAKE)->applyLineageContext($identity);
        $intent->setExchangeOrderId($exchangeOrderId);
        $this->em->persist($intent);
        $this->em->flush();

        return $this->tradeLineageManager()->ensureForIntent($intent, $identity);
    }

    private function legacyLineage(): TradeLineage
    {
        $intent = $this->intent('cid-legacy', Exchange::BITMART)
            ->setDecisionKey('bitmart:perpetual:BTCUSDT:1m:1764161200:long:scalper:v1');
        $this->em->persist($intent);
        $this->em->flush();

        return $this->tradeLineageManager()->ensureForIntent($intent, [
            'internal_trade_id' => 'itd-legacy',
            'run_id' => 'run-legacy',
        ]);
    }

    private function intent(string $clientOrderId, Exchange $exchange): OrderIntent
    {
        return (new OrderIntent())
            ->setExchange($exchange)
            ->setMarketType(MarketType::PERPETUAL)
            ->setSymbol('BTCUSDT')
            ->setSide(1)
            ->setType(OrderIntent::TYPE_LIMIT)
            ->setOpenType(OrderIntent::OPEN_TYPE_ISOLATED)
            ->setPositionMode(OrderIntent::POSITION_MODE_ONE_WAY)
            ->setSize(1)
            ->setClientOrderId($clientOrderId)
            ->setPresetMode(OrderIntent::PRESET_MODE_NONE);
    }

    private function listener(CanonicalIdentityWarningLogger $warnings): TradeLifecycleLoggerListener
    {
        return new TradeLifecycleLoggerListener(
            new TradeLifecycleLogger($this->em, new class implements ClockInterface {
                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable('2026-06-23 10:06:00 UTC');
                }
            }),
            $this->lifecycleRepository(),
            null,
            $this->tradeLineageManager(),
            null,
            $warnings,
        );
    }

    private function event(string $eventType, string $positionId): TradeLifecycleEvent
    {
        $event = $this->em->getRepository(TradeLifecycleEvent::class)->findOneBy([
            'eventType' => $eventType,
            'positionId' => $positionId,
        ]);
        self::assertInstanceOf(TradeLifecycleEvent::class, $event, $eventType);

        return $event;
    }

    /** @return array<string, mixed> */
    private static function identity(TradeLifecycleEvent $event): array
    {
        return [
            'mode_id' => $event->getModeId(),
            'mode_version' => $event->getModeVersion(),
            'setup_id' => $event->getSetupId(),
            'setup_version' => $event->getSetupVersion(),
            'side' => $event->getSide(),
            'decision_id' => $event->getDecisionId(),
            'decision_key' => $event->getDecisionKey(),
            'intent_id' => $event->getIntentId(),
            'order_id' => $event->getOrderId(),
            'position_id' => $event->getPositionId(),
            'trade_id' => $event->getTradeId(),
            'correlation_run_id' => $event->getCorrelationRunId(),
            'orchestration_run_id' => $event->getOrchestrationRunId(),
            'orchestration_set_id' => $event->getOrchestrationSetId(),
            'orchestration_dashboard_id' => $event->getOrchestrationDashboardId(),
            'internal_trade_id' => $event->getInternalTradeId(),
            'exchange' => $event->getExchange(),
            'market_type' => $event->getMarketType(),
        ];
    }

    private function tradeLineageManager(): TradeLineageManager
    {
        /** @var TradeLineageRepository $repository */
        $repository = $this->em->getRepository(TradeLineage::class);

        return new TradeLineageManager($repository, $this->em, new NullLogger());
    }

    private function lifecycleRepository(): TradeLifecycleEventRepository
    {
        /** @var TradeLifecycleEventRepository $repository */
        $repository = $this->em->getRepository(TradeLifecycleEvent::class);

        return $repository;
    }

    /** @return list<\Doctrine\ORM\Mapping\ClassMetadata<object>> */
    private function metadata(): array
    {
        return array_map(
            fn (string $class) => $this->em->getClassMetadata($class),
            [OrderIntent::class, TradeLineage::class, TradeLifecycleEvent::class],
        );
    }
}

/** Collects the log records of the listener. */
final class CanonicalIdentityWarningLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string, 2: array<mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
