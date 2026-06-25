<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Entity\OrderIntent;
use App\Entity\TradeLifecycleEvent;
use App\Entity\TradeLineage;
use App\Trading\Lineage\ReadModel\LineageReadCriteria;
use App\Trading\Lineage\ReadModel\LineageReadException;
use App\Trading\Lineage\ReadModel\LineageReadService;
use App\Trading\Lineage\ReadModel\LineageReadStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LineageReadService::class)]
final class LineageReadServiceTest extends TestCase
{
    public function testCompleteLineageReturnsWhitelistedPayload(): void
    {
        $lineage = $this->lineage('trade-1')
            ->setOrderIntent($this->intent(42, 'trade-1'))
            ->setExchangeOrderId('EX-1')
            ->setPositionId('POS-1');

        $events = [
            $this->event('order_submitted', 'trade-1'),
            $this->event('position_opened', 'trade-1'),
            $this->event('position_closed', 'trade-1'),
        ];

        $page = $this->service([$lineage], ['trade-1' => $events])->search(
            LineageReadCriteria::forIdentifier('internal_trade_id', 'trade-1', limit: 10, offset: 0),
        );

        self::assertSame(1, $page->total);
        self::assertFalse($page->hasMore);
        self::assertSame(10, $page->limit);
        self::assertSame(0, $page->offset);

        $item = $page->items[0];
        self::assertSame('complete', $item['completeness_status']);
        self::assertSame([], $item['quality_flags']);
        self::assertSame('trade-1', $item['lineage']['internal_trade_id']);
        self::assertSame(42, $item['order_intent']['id']);
        self::assertCount(3, $item['lifecycle_events']);
        self::assertArrayNotHasKey('extra', $item['lifecycle_events'][0]);
        self::assertArrayNotHasKey('raw_inputs', $item['order_intent']);
        self::assertArrayNotHasKey('validation_errors', $item['order_intent']);
        self::assertStringNotContainsString('SECRET', json_encode($item, JSON_THROW_ON_ERROR));
    }

    public function testCompletenessStatusesExposeSpecificPartialReasons(): void
    {
        $cases = [
            'legacy' => $this->lineage('legacy')->setOrigin('legacy'),
            'missing_order_intent' => $this->lineage('missing-intent')->setExchangeOrderId('EX-1')->setPositionId('POS-1')->setOrigin('orchestrator'),
            'missing_exchange_order_id' => $this->lineage('missing-exchange')->setOrderIntent($this->intent(1, 'missing-exchange'))->setPositionId('POS-1'),
            'missing_position_id' => $this->lineage('missing-position')->setOrderIntent($this->intent(2, 'missing-position'))->setExchangeOrderId('EX-1'),
            'missing_close_event' => $this->lineage('missing-close')->setOrderIntent($this->intent(3, 'missing-close'))->setExchangeOrderId('EX-1')->setPositionId('POS-1'),
            'partial' => $this->lineage('partial')->setOrderIntent($this->intent(4, 'partial'))->setExchangeOrderId('EX-1')->setPositionId('POS-1')->setOrchestrationDashboardId(null),
        ];

        foreach ($cases as $expectedStatus => $lineage) {
            $events = $expectedStatus === 'missing_close_event'
                ? [$this->event('order_submitted', $lineage->getInternalTradeId())]
                : [$this->event('position_closed', $lineage->getInternalTradeId())];

            $page = $this->service([$lineage], [$lineage->getInternalTradeId() => $events])->search(
                LineageReadCriteria::forIdentifier('internal_trade_id', $lineage->getInternalTradeId(), limit: 10, offset: 0),
            );

            self::assertSame($expectedStatus, $page->items[0]['completeness_status'], $expectedStatus);
            self::assertContains($expectedStatus, $page->items[0]['quality_flags'], $expectedStatus);
        }
    }

    public function testUnmatchedEventWithoutPersistentLineageIsVisible(): void
    {
        $event = $this->event('position_closed', null)->setPositionId('POS-404');

        $page = $this->service([], [], [$event])->search(
            LineageReadCriteria::forVenueIdentifier('position_id', 'POS-404', 'okx', 'perpetual', limit: 10, offset: 0),
        );

        self::assertSame(1, $page->total);
        self::assertSame('unmatched', $page->items[0]['completeness_status']);
        self::assertContains('unmatched', $page->items[0]['quality_flags']);
        self::assertNull($page->items[0]['lineage']);
        self::assertCount(1, $page->items[0]['lifecycle_events']);
    }

    public function testIdentifierConflictIsNeverResolvedSilently(): void
    {
        $lineageA = $this->lineage('trade-a')->setExchangeOrderId('EX-1');
        $lineageB = $this->lineage('trade-b')->setExchangeOrderId('EX-1');

        $this->expectException(LineageReadException::class);
        $this->expectExceptionCode(409);

        $this->service([$lineageA, $lineageB])->search(
            LineageReadCriteria::forVenueIdentifier('exchange_order_id', 'EX-1', 'bitmart', 'perpetual', limit: 10, offset: 0),
        );
    }

    public function testLimitIsCappedAtOneHundred(): void
    {
        $criteria = LineageReadCriteria::forIdentifier('orchestration_run_id', 'run-1', limit: 500, offset: 0);

        self::assertSame(100, $criteria->limit);
    }

    /**
     * @param TradeLineage[] $lineages
     * @param array<string, TradeLifecycleEvent[]> $eventsByTrade
     * @param TradeLifecycleEvent[] $unmatchedEvents
     */
    private function service(array $lineages, array $eventsByTrade = [], array $unmatchedEvents = []): LineageReadService
    {
        return new LineageReadService(new class($lineages, $eventsByTrade, $unmatchedEvents) implements LineageReadStoreInterface {
            /**
             * @param TradeLineage[] $lineages
             * @param array<string, TradeLifecycleEvent[]> $eventsByTrade
             * @param TradeLifecycleEvent[] $unmatchedEvents
             */
            public function __construct(
                private readonly array $lineages,
                private readonly array $eventsByTrade,
                private readonly array $unmatchedEvents,
            ) {
            }

            public function count(LineageReadCriteria $criteria): int
            {
                return count($this->find($criteria));
            }

            public function find(LineageReadCriteria $criteria): array
            {
                return array_values(array_filter(
                    $this->lineages,
                    static fn (TradeLineage $lineage): bool => match ($criteria->kind) {
                        'internal_trade_id' => $lineage->getInternalTradeId() === $criteria->value,
                        'orchestration_run_id' => $lineage->getOrchestrationRunId() === $criteria->value,
                        'exchange_order_id' => $lineage->getExchangeOrderId() === $criteria->value
                            && $lineage->getExchange() === $criteria->exchange
                            && $lineage->getMarketType() === $criteria->marketType,
                        'position_id' => $lineage->getPositionId() === $criteria->value
                            && $lineage->getExchange() === $criteria->exchange
                            && $lineage->getMarketType() === $criteria->marketType,
                        default => false,
                    },
                ));
            }

            public function findUnmatchedEvents(LineageReadCriteria $criteria): array
            {
                if (!in_array($criteria->kind, ['position_id', 'exchange_order_id', 'client_order_id'], true)) {
                    return [];
                }

                return array_values(array_filter(
                    $this->unmatchedEvents,
                    static fn (TradeLifecycleEvent $event): bool => match ($criteria->kind) {
                        'position_id' => $event->getPositionId() === $criteria->value,
                        'exchange_order_id' => $event->getOrderId() === $criteria->value,
                        'client_order_id' => $event->getClientOrderId() === $criteria->value,
                    },
                ));
            }

            public function findEventsForLineage(TradeLineage $lineage, int $limit): array
            {
                return array_slice($this->eventsByTrade[$lineage->getInternalTradeId()] ?? [], 0, $limit);
            }

            public function countEventsForLineage(TradeLineage $lineage): int
            {
                return count($this->eventsByTrade[$lineage->getInternalTradeId()] ?? []);
            }
        });
    }

    private function lineage(string $internalTradeId): TradeLineage
    {
        return (new TradeLineage($internalTradeId, 'client-' . $internalTradeId, 'BTCUSDT'))
            ->setExchange('bitmart')
            ->setMarketType('perpetual')
            ->setOrigin('orchestrator')
            ->setRunId('run-1')
            ->setCorrelationRunId('run-1')
            ->setOrchestrationRunId('orun-1')
            ->setOrchestrationSetId('set-1')
            ->setOrchestrationDashboardId('dash-1')
            ->setProfile('scalper_micro');
    }

    private function intent(int $id, string $internalTradeId): OrderIntent
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
            ->setInternalTradeId($internalTradeId)
            ->setRawInputs(['secret' => 'SECRET'])
            ->setValidationErrors(['provider_payload' => 'SECRET']);

        $this->setId($intent, $id);

        return $intent;
    }

    private function event(string $type, ?string $internalTradeId): TradeLifecycleEvent
    {
        return (new TradeLifecycleEvent('BTCUSDT', $type, new \DateTimeImmutable('2026-06-25T10:00:00+00:00')))
            ->setExchange('bitmart')
            ->setMarketType('perpetual')
            ->setInternalTradeId($internalTradeId)
            ->setOrderId('EX-1')
            ->setClientOrderId('client-' . ($internalTradeId ?? 'none'))
            ->setExtra(['provider_payload' => 'SECRET']);
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
