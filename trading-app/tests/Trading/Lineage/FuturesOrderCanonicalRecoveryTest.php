<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Entity\FuturesOrder;
use App\Entity\OrderIntent;
use App\MtfRunner\Service\FuturesOrderSyncService;
use App\Provider\Context\ExchangeContext;
use App\Repository\FuturesOrderRepository;
use App\Repository\FuturesOrderTradeRepository;
use App\Repository\FuturesPlanOrderRepository;
use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\Persistence\OrderIntentRecoverySource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FuturesOrder::class)]
#[CoversClass(FuturesOrderSyncService::class)]
final class FuturesOrderCanonicalRecoveryTest extends TestCase
{
    public function testCopiesExactIdentityOnlyFromTypedOrderIntentPredecessor(): void
    {
        [$intent, $identity] = $this->intent();
        $order = $this->order();

        $order->applyOrderIntentLineage($intent);

        $expected = $intent->requireExecutionLineageContext()
            ->withExecution('exchange-order-1', $identity->positionId, $identity->tradeId);
        self::assertSame($expected->toArray(), $order->requireLineageContext()->toArray());
        self::assertSame('canonical', $order->lineageClassification());
        self::assertSame('scalping', $order->getModeId());
        self::assertSame('scalping.pullback.long', $order->getSetupId());
        self::assertSame($intent, $order->getOrderIntent());
    }

    public function testDuplicateRecoveryIsIdempotent(): void
    {
        [$intent] = $this->intent();
        $order = $this->order();

        $order->applyOrderIntentLineage($intent);
        $first = $order->requireLineageContext()->toArray();
        $order->applyOrderIntentLineage($intent);

        self::assertSame($first, $order->requireLineageContext()->toArray());
    }

    public function testRejectsSameExternalOrderRecoveredFromDifferentRun(): void
    {
        [$intent, $identity] = $this->intent();
        $order = $this->order()->applyOrderIntentLineage($intent);
        $replay = $identity->asReplay('run-replay', 'run-fixture', 'run-fixture', 2);
        $otherIntent = $this->buildIntent($replay);

        $this->expectExceptionMessage('canonical_identity_mismatch:futures_order');

        $order->applyOrderIntentLineage($otherIntent);
    }

    public function testRejectsPredecessorWithConflictingSymbol(): void
    {
        [$intent] = $this->intent();
        $order = $this->order()->setSymbol('ETHUSDT');

        $this->expectExceptionMessage('canonical_identity_mismatch:symbol');

        $order->applyOrderIntentLineage($intent);
    }

    public function testClassifiesConflictingStructuredProjectionAsIncomplete(): void
    {
        [$intent] = $this->intent();
        $order = $this->order()->applyOrderIntentLineage($intent);
        (new \ReflectionProperty(FuturesOrder::class, 'canonicalModeId'))->setValue($order, 'day_trading');

        self::assertSame('incomplete', $order->lineageClassification());

        $this->expectExceptionMessage('canonical_identity_mismatch:persisted_mode_id');
        $order->requireLineageContext();
    }

    public function testRejectsProjectionWhoseJsonEnvelopeWasRemoved(): void
    {
        [$intent] = $this->intent();
        $order = $this->order()->applyOrderIntentLineage($intent);
        (new \ReflectionProperty(FuturesOrder::class, 'canonicalIdentity'))->setValue($order, null);

        self::assertSame('incomplete', $order->lineageClassification());

        $this->expectExceptionMessage('canonical_identity_incomplete:futures_order');
        $order->applyOrderIntentLineage($intent);
    }

    /** @dataProvider authoritativeOrderMutations */
    public function testRejectsCanonicalOrderWhenAuthoritativeBusinessFieldChanges(string $setter, mixed $value, string $error): void
    {
        [$intent] = $this->intent();
        $order = $this->order()->applyOrderIntentLineage($intent);
        $order->{$setter}($value);

        self::assertSame('incomplete', $order->lineageClassification());
        $this->expectExceptionMessage($error);
        $order->requireLineageContext();
    }

    /** @return iterable<string,array{string,mixed,string}> */
    public static function authoritativeOrderMutations(): iterable
    {
        yield 'symbol' => ['setSymbol', 'ETHUSDT', 'canonical_identity_mismatch:symbol'];
        yield 'side' => ['setSide', 4, 'canonical_identity_mismatch:side'];
        yield 'exchange' => ['setExchange', 'bitmart', 'canonical_identity_mismatch:exchange'];
        yield 'market' => ['setMarketType', 'spot', 'canonical_identity_mismatch:market_type'];
        yield 'order' => ['setOrderId', 'other-order', 'canonical_identity_mismatch:exchange_order_id'];
        yield 'client' => ['setClientOrderId', 'other-client', 'canonical_identity_mismatch:client_order_id'];
    }

    public function testRejectsAmbiguousOrderAndClientPredecessors(): void
    {
        [$byOrder] = $this->intent();
        [$byClient] = $this->intent();
        $source = new class($byOrder, $byClient) implements OrderIntentRecoverySource {
            public function __construct(
                private readonly OrderIntent $byOrder,
                private readonly OrderIntent $byClient,
            ) {}

            public function findOneByClientOrderId(string $clientOrderId, ?ExchangeContext $context = null): ?OrderIntent
            {
                return $this->byClient;
            }

            public function findOneByOrderId(string $orderId, ?ExchangeContext $context = null): ?OrderIntent
            {
                return $this->byOrder;
            }

            public function findByOrderIdForRecovery(string $orderId, ?ExchangeContext $context = null): array
            {
                return [$this->byOrder];
            }
        };
        $service = new FuturesOrderSyncService(
            (new \ReflectionClass(FuturesOrderRepository::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(FuturesPlanOrderRepository::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(FuturesOrderTradeRepository::class))->newInstanceWithoutConstructor(),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            $source,
        );
        $resolve = new \ReflectionMethod(FuturesOrderSyncService::class, 'findExactOrderIntent');

        $this->expectExceptionMessage('canonical_identity_mismatch:order_intent_predecessor');

        $resolve->invoke(
            $service,
            'exchange-order-1',
            'client-order-1',
            new ExchangeContext(\App\Common\Enum\Exchange::FAKE, \App\Common\Enum\MarketType::PERPETUAL),
        );
    }

    public function testRejectsMultipleOrderIdPredecessorsBeforeClientFallback(): void
    {
        [$first] = $this->intent();
        [$second] = $this->intent();
        $source = new class($first, $second) implements OrderIntentRecoverySource {
            public function __construct(
                private readonly OrderIntent $first,
                private readonly OrderIntent $second,
            ) {}

            public function findOneByClientOrderId(string $clientOrderId, ?ExchangeContext $context = null): ?OrderIntent
            {
                return $this->first;
            }

            public function findOneByOrderId(string $orderId, ?ExchangeContext $context = null): ?OrderIntent
            {
                return null;
            }

            public function findByOrderIdForRecovery(string $orderId, ?ExchangeContext $context = null): array
            {
                return [$this->first, $this->second];
            }
        };
        $service = new FuturesOrderSyncService(
            (new \ReflectionClass(FuturesOrderRepository::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(FuturesPlanOrderRepository::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(FuturesOrderTradeRepository::class))->newInstanceWithoutConstructor(),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            $source,
        );
        $resolve = new \ReflectionMethod(FuturesOrderSyncService::class, 'findExactOrderIntent');

        $this->expectExceptionMessage('canonical_identity_mismatch:order_intent_predecessor');
        $resolve->invoke(
            $service,
            'exchange-order-1',
            'client-order-1',
            new ExchangeContext(\App\Common\Enum\Exchange::FAKE, \App\Common\Enum\MarketType::PERPETUAL),
        );
    }

    /** @return array{OrderIntent,LineageContext} */
    private function intent(): array
    {
        $data = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        $data['dry_run'] = true;
        $identity = LineageContext::fromArray($data)
            ->withDecision('018f47a2-4f42-7e1b-8d3a-4dc9571bb11b', 'decision-fixture')
            ->withIntent('intent-fixture');

        return [$this->buildIntent($identity), $identity];
    }

    private function buildIntent(LineageContext $identity): OrderIntent
    {
        return (new OrderIntent())
            ->setExchange('fake')
            ->setMarketType('perpetual')
            ->setSymbol('BTCUSDT')
            ->setSide(1)
            ->setClientOrderId('client-order-1')
            ->setOrderId('exchange-order-1')
            ->setExchangeOrderId('exchange-order-1')
            ->applyLineageContext($identity);
    }

    private function order(): FuturesOrder
    {
        return (new FuturesOrder())
            ->setExchange('fake')
            ->setMarketType('perpetual')
            ->setSymbol('BTCUSDT')
            ->setSide(1)
            ->setClientOrderId('client-order-1')
            ->setOrderId('exchange-order-1');
    }
}
