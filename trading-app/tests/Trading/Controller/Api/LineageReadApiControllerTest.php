<?php

declare(strict_types=1);

namespace App\Tests\Trading\Controller\Api;

use App\Entity\OrderIntent;
use App\Entity\TradeLifecycleEvent;
use App\Entity\TradeLineage;
use App\Trading\Controller\Api\LineageReadApiController;
use App\Trading\Lineage\ReadModel\LineageReadCriteria;
use App\Trading\Lineage\ReadModel\LineageReadService;
use App\Trading\Lineage\ReadModel\LineageReadStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(LineageReadApiController::class)]
final class LineageReadApiControllerTest extends TestCase
{
    public function testMissingIdentifierReturnsStructured400(): void
    {
        $response = $this->controller([])->search(new Request());

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('missing_identifier', $this->json($response)['error']['code']);
    }

    public function testVenueIdentifierRequiresExchangeAndMarketType(): void
    {
        $response = $this->controller([])->search(new Request(['position_id' => 'POS-1', 'exchange' => 'bitmart']));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('missing_venue', $this->json($response)['error']['code']);
    }

    public function testUnknownIdentifierReturns404(): void
    {
        $response = $this->controller([])->search(new Request(['internal_trade_id' => 'missing']));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('lineage_not_found', $this->json($response)['error']['code']);
    }

    public function testConflictReturns409(): void
    {
        $lineageA = $this->lineage('trade-a')->setExchangeOrderId('EX-DUP');
        $lineageB = $this->lineage('trade-b')->setExchangeOrderId('EX-DUP');

        $response = $this->controller([$lineageA, $lineageB])->search(new Request([
            'exchange_order_id' => 'EX-DUP',
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
        ]));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('identifier_conflict', $body['error']['code']);
        self::assertSame('identifier_conflict', $body['completeness_status']);
        self::assertSame(['identifier_conflict'], $body['quality_flags']);
    }

    public function testSearchCapsLimitAndReturnsRedactedItems(): void
    {
        $lineage = $this->lineage('trade-1')
            ->setOrderIntent($this->intent(7, 'trade-1'))
            ->setExchangeOrderId('EX-1')
            ->setPositionId('POS-1');

        $response = $this->controller([$lineage], [
            'trade-1' => [$this->event('position_closed', 'trade-1')],
        ])->search(new Request([
            'internal_trade_id' => 'trade-1',
            'limit' => '500',
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(100, $body['pagination']['limit']);
        self::assertSame('complete', $body['data'][0]['completeness_status']);
        self::assertSame([], $body['data'][0]['quality_flags']);
        self::assertArrayNotHasKey('extra', $body['data'][0]['lifecycle_events'][0]);
        self::assertStringNotContainsString('SECRET', (string) $response->getContent());
    }

    /**
     * @param TradeLineage[] $lineages
     * @param array<string, TradeLifecycleEvent[]> $eventsByTrade
     */
    private function controller(array $lineages, array $eventsByTrade = []): LineageReadApiController
    {
        $controller = new LineageReadApiController(new LineageReadService(new class($lineages, $eventsByTrade) implements LineageReadStoreInterface {
            /**
             * @param TradeLineage[] $lineages
             * @param array<string, TradeLifecycleEvent[]> $eventsByTrade
             */
            public function __construct(private readonly array $lineages, private readonly array $eventsByTrade)
            {
            }

            public function count(LineageReadCriteria $criteria): int
            {
                return count($this->find($criteria));
            }

            public function find(LineageReadCriteria $criteria): array
            {
                return array_values(array_filter($this->lineages, static function (TradeLineage $lineage) use ($criteria): bool {
                    return match ($criteria->kind) {
                        'internal_trade_id' => $lineage->getInternalTradeId() === $criteria->value,
                        'exchange_order_id' => $lineage->getExchangeOrderId() === $criteria->value
                            && $lineage->getExchange() === $criteria->exchange
                            && $lineage->getMarketType() === $criteria->marketType,
                        default => false,
                    };
                }));
            }

            public function findUnmatchedEvents(LineageReadCriteria $criteria): array
            {
                return [];
            }

            public function findEventsForLineage(TradeLineage $lineage, int $limit): array
            {
                return array_slice($this->eventsByTrade[$lineage->getInternalTradeId()] ?? [], 0, $limit);
            }

            public function countEventsForLineage(TradeLineage $lineage): int
            {
                return count($this->eventsByTrade[$lineage->getInternalTradeId()] ?? []);
            }
        }));

        $controller->setContainer(new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('not available: ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        });

        return $controller;
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
            ->setOrchestrationDashboardId('dash-1');
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
            ->setRawInputs(['secret' => 'SECRET']);

        $property = new \ReflectionProperty($intent, 'id');
        $property->setAccessible(true);
        $property->setValue($intent, $id);

        return $intent;
    }

    private function event(string $type, string $internalTradeId): TradeLifecycleEvent
    {
        return (new TradeLifecycleEvent('BTCUSDT', $type, new \DateTimeImmutable('2026-06-25T10:00:00+00:00')))
            ->setExchange('bitmart')
            ->setMarketType('perpetual')
            ->setInternalTradeId($internalTradeId)
            ->setOrderId('EX-1')
            ->setExtra(['provider_payload' => 'SECRET']);
    }

    /**
     * @return array<string,mixed>
     */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
