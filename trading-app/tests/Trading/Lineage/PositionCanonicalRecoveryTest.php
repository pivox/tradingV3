<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Entity\FuturesOrder;
use App\Entity\FuturesOrderTrade;
use App\Entity\OrderIntent;
use App\Entity\Position;
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\Persistence\CanonicalPositionPredecessor;
use App\Trading\Lineage\Persistence\CanonicalPositionEvidence;
use App\Trading\Lineage\Persistence\CanonicalPositionRecoveryService;
use App\Trading\Lineage\Persistence\FuturesOrderRecoverySource;
use App\Trading\Lineage\Persistence\FuturesOrderTradeRecoverySource;
use App\Trading\Lineage\Persistence\OrderIntentRecoverySource;
use App\Provider\Context\ExchangeContext;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Position::class)]
final class PositionCanonicalRecoveryTest extends TestCase
{
    public function testCopiesExactIdentityFromTypedOpeningPredecessors(): void
    {
        [$order, $fill] = $this->predecessors();
        $source = $order->requireLineageContext();
        $position = new Position('BTCUSDT', 'LONG', 'fake', 'perpetual');

        $position->applyCanonicalPredecessor(new CanonicalPositionPredecessor(
            $order,
            $fill,
            'fake-position-1',
            $source,
        ));

        self::assertSame($source->toArray(), $position->requireLineageContext()->toArray());
        self::assertSame('fake-position-1', $position->getCanonicalExchangePositionId());
        self::assertSame($order, $position->getOpeningOrder());
        self::assertSame($fill, $position->getOpeningFill());
        self::assertSame('canonical', $position->lineageClassification());
    }

    public function testRetryIsIdempotentButAnotherPositionCycleIsRejected(): void
    {
        [$order, $fill] = $this->predecessors();
        $predecessor = new CanonicalPositionPredecessor($order, $fill, 'fake-position-1', $order->requireLineageContext());
        $position = (new Position('BTCUSDT', 'LONG', 'fake', 'perpetual'))
            ->applyCanonicalPredecessor($predecessor)
            ->applyCanonicalPredecessor($predecessor);

        self::assertSame('canonical', $position->lineageClassification());
        $this->expectExceptionMessage('canonical_identity_mismatch:exchange_position_id');
        $position->applyCanonicalPredecessor(new CanonicalPositionPredecessor(
            $order,
            $fill,
            'fake-position-2',
            $order->requireLineageContext(),
        ));
    }

    public function testRejectsBoundaryConflictAndPartialProjection(): void
    {
        [$order, $fill] = $this->predecessors();
        $position = new Position('ETHUSDT', 'LONG', 'fake', 'perpetual');

        $this->expectExceptionMessage('canonical_identity_mismatch:symbol');
        $position->applyCanonicalPredecessor(new CanonicalPositionPredecessor(
            $order,
            $fill,
            'fake-position-1',
            $order->requireLineageContext(),
        ));
    }

    public function testExternalPositionProjectionAloneIsIncomplete(): void
    {
        [$order, $fill] = $this->predecessors();
        $position = new Position('BTCUSDT', 'LONG', 'fake', 'perpetual');
        (new \ReflectionProperty(Position::class, 'canonicalExchangePositionId'))->setValue($position, 'fake-position-1');

        self::assertSame('incomplete', $position->lineageClassification());
        $this->expectExceptionMessage('canonical_identity_incomplete:position');
        $position->applyCanonicalPredecessor(new CanonicalPositionPredecessor(
            $order,
            $fill,
            'fake-position-1',
            $order->requireLineageContext(),
        ));
    }

    public function testResolverRequiresEveryProvidedIdentifierToResolveAndConverge(): void
    {
        [$order, $fill] = $this->predecessors();
        $intent = $order->getOrderIntent();
        self::assertInstanceOf(OrderIntent::class, $intent);
        $context = new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL);

        $resolved = $this->recovery($order, $order, $fill, $intent, $intent)->resolve(
            new CanonicalPositionEvidence('fake-position-1', 'exchange-order-1', 'client-order-1', 'exchange-fill-1'),
            $context,
        );
        self::assertNotNull($resolved);
        self::assertSame($order->requireLineageContext()->toArray(), $resolved->context->toArray());

        $this->expectExceptionMessage('canonical_identity_missing:position_fill_predecessor');
        $this->recovery($order, $order, null, $intent, $intent)->resolve(
            new CanonicalPositionEvidence('fake-position-1', 'exchange-order-1', 'client-order-1', 'unknown-fill'),
            $context,
        );
    }

    public function testResolverRejectsUnknownOrderEvenWhenClientOrderResolves(): void
    {
        [$order] = $this->predecessors();
        $intent = $order->getOrderIntent();
        self::assertInstanceOf(OrderIntent::class, $intent);

        $this->expectExceptionMessage('canonical_identity_missing:position_order_predecessor');
        $this->recovery(null, $order, null, null, $intent)->resolve(
            new CanonicalPositionEvidence('fake-position-1', 'unknown-order', 'client-order-1'),
            new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL),
        );
    }

    public function testCloseFillCannotBecomeOpeningFill(): void
    {
        [$order] = $this->predecessors();
        $closeFill = (new FuturesOrderTrade())
            ->setExchange('fake')->setMarketType('perpetual')->setTradeId('close-fill')
            ->setOrderId('exchange-order-1')->setSymbol('BTCUSDT')->setSide(2)
            ->setPrice('100')->setSize(1)->setTradeTime(2);

        $this->expectExceptionMessage('canonical_identity_mismatch:fill_order_side');
        $closeFill->applyFuturesOrderLineage($order);
    }

    private function recovery(
        ?FuturesOrder $byOrder,
        ?FuturesOrder $byClient,
        ?FuturesOrderTrade $fill,
        ?OrderIntent $intentByOrder,
        ?OrderIntent $intentByClient,
    ): CanonicalPositionRecoveryService {
        $orders = new class($byOrder, $byClient) implements FuturesOrderRecoverySource {
            public function __construct(private ?FuturesOrder $byOrder, private ?FuturesOrder $byClient) {}
            public function findOneByOrderId(string $orderId, ?ExchangeContext $context = null): ?FuturesOrder { return $this->byOrder; }
            public function findOneByClientOrderId(string $clientOrderId, ?ExchangeContext $context = null): ?FuturesOrder { return $this->byClient; }
        };
        $fills = new class($fill) implements FuturesOrderTradeRecoverySource {
            public function __construct(private ?FuturesOrderTrade $fill) {}
            public function findOneByTradeId(string $tradeId, ?ExchangeContext $context = null): ?FuturesOrderTrade { return $this->fill; }
        };
        $intents = new class($intentByOrder, $intentByClient) implements OrderIntentRecoverySource {
            public function __construct(private ?OrderIntent $byOrder, private ?OrderIntent $byClient) {}
            public function findOneByClientOrderId(string $clientOrderId, ?ExchangeContext $context = null): ?OrderIntent { return $this->byClient; }
            public function findOneByOrderId(string $orderId, ?ExchangeContext $context = null): ?OrderIntent { return $this->byOrder; }
            public function findByOrderIdForRecovery(string $orderId, ?ExchangeContext $context = null): array { return $this->byOrder !== null ? [$this->byOrder] : []; }
        };

        return new CanonicalPositionRecoveryService($orders, $fills, $intents);
    }

    /** @return array{FuturesOrder,FuturesOrderTrade} */
    private function predecessors(): array
    {
        $data = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        $data['dry_run'] = true;
        $identity = LineageContext::fromArray($data)
            ->withDecision('018f47a2-4f42-7e1b-8d3a-4dc9571bb11b', 'decision-fixture')
            ->withIntent('intent-fixture');
        $intent = (new OrderIntent())
            ->setExchange('fake')->setMarketType('perpetual')->setSymbol('BTCUSDT')->setSide(1)
            ->setClientOrderId('client-order-1')->setOrderId('exchange-order-1')
            ->setExchangeOrderId('exchange-order-1')->applyLineageContext($identity);
        $order = (new FuturesOrder())
            ->setExchange('fake')->setMarketType('perpetual')->setSymbol('BTCUSDT')->setSide(1)
            ->setClientOrderId('client-order-1')->setOrderId('exchange-order-1')
            ->applyOrderIntentLineage($intent);
        $fill = (new FuturesOrderTrade())
            ->setExchange('fake')->setMarketType('perpetual')->setTradeId('exchange-fill-1')
            ->setOrderId('exchange-order-1')->setSymbol('BTCUSDT')->setSide(1)
            ->setPrice('100')->setSize(1)->setTradeTime(1)
            ->applyFuturesOrderLineage($order);

        return [$order, $fill];
    }
}
