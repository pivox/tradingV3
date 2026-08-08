<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Entity\FuturesOrder;
use App\Entity\FuturesOrderTrade;
use App\Entity\OrderIntent;
use App\Trading\Lineage\LineageContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FuturesOrderTrade::class)]
final class FuturesOrderTradeCanonicalRecoveryTest extends TestCase
{
    public function testCopiesExactCanonicalIdentityFromOrderToFill(): void
    {
        $order = $this->order();
        $fill = $this->fill()->applyFuturesOrderLineage($order);

        $expected = $order->requireLineageContext();
        self::assertSame($expected->toArray(), $fill->requireLineageContext()->toArray());
        self::assertSame('canonical', $fill->lineageClassification());
        self::assertSame('exchange-trade-1', $fill->getCanonicalExchangeFillId());
        self::assertSame($order, $fill->getFuturesOrder());
    }

    public function testDuplicateFillRecoveryIsIdempotent(): void
    {
        $order = $this->order();
        $fill = $this->fill()->applyFuturesOrderLineage($order);
        $first = $fill->requireLineageContext()->toArray();

        $fill->applyFuturesOrderLineage($order);

        self::assertSame($first, $fill->requireLineageContext()->toArray());
    }

    /** @dataProvider boundaryMutations */
    public function testRejectsFillBoundaryConflict(string $setter, mixed $value, string $error): void
    {
        $fill = $this->fill();
        $fill->{$setter}($value);

        $this->expectExceptionMessage($error);
        $fill->applyFuturesOrderLineage($this->order());
    }

    /** @return iterable<string,array{string,mixed,string}> */
    public static function boundaryMutations(): iterable
    {
        yield 'order' => ['setOrderId', 'other-order', 'canonical_identity_mismatch:exchange_order_id'];
        yield 'trade' => ['setTradeId', null, 'canonical_identity_missing:exchange_trade_id'];
        yield 'symbol' => ['setSymbol', 'ETHUSDT', 'canonical_identity_mismatch:symbol'];
        yield 'side' => ['setSide', 4, 'canonical_identity_mismatch:fill_order_side'];
        yield 'exchange' => ['setExchange', 'bitmart', 'canonical_identity_mismatch:exchange'];
        yield 'market' => ['setMarketType', 'spot', 'canonical_identity_mismatch:market_type'];
    }

    public function testExternalFillIdDoesNotOverwriteLogicalTradeId(): void
    {
        $fill = $this->fill()->applyFuturesOrderLineage($this->order());

        self::assertSame('exchange-trade-1', $fill->getTradeId());
        self::assertNull($fill->requireLineageContext()->tradeId);
    }

    public function testClassifiesFillAsIncompleteWhenExternalFillIdChanges(): void
    {
        $fill = $this->fill()->applyFuturesOrderLineage($this->order());
        $fill->setTradeId('other-trade');

        self::assertSame('incomplete', $fill->lineageClassification());
        $this->expectExceptionMessage('canonical_identity_mismatch:exchange_trade_id');
        $fill->requireLineageContext();
    }

    public function testExternalFillProjectionAloneIsIncompleteAndCannotBeOverwritten(): void
    {
        $fill = $this->fill();
        (new \ReflectionProperty(FuturesOrderTrade::class, 'canonicalExchangeFillId'))
            ->setValue($fill, 'exchange-trade-1');

        self::assertSame('incomplete', $fill->lineageClassification());
        $this->expectExceptionMessage('canonical_identity_incomplete:futures_order_trade');
        $fill->applyFuturesOrderLineage($this->order());
    }

    private function order(): FuturesOrder
    {
        $data = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        $data['dry_run'] = true;
        $identity = LineageContext::fromArray($data)
            ->withDecision('018f47a2-4f42-7e1b-8d3a-4dc9571bb11b', 'decision-fixture')
            ->withIntent('intent-fixture');
        $intent = (new OrderIntent())
            ->setExchange('fake')
            ->setMarketType('perpetual')
            ->setSymbol('BTCUSDT')
            ->setSide(1)
            ->setClientOrderId('client-order-1')
            ->setOrderId('exchange-order-1')
            ->setExchangeOrderId('exchange-order-1')
            ->applyLineageContext($identity);

        return (new FuturesOrder())
            ->setExchange('fake')
            ->setMarketType('perpetual')
            ->setSymbol('BTCUSDT')
            ->setSide(1)
            ->setClientOrderId('client-order-1')
            ->setOrderId('exchange-order-1')
            ->applyOrderIntentLineage($intent);
    }

    private function fill(): FuturesOrderTrade
    {
        return (new FuturesOrderTrade())
            ->setExchange('fake')
            ->setMarketType('perpetual')
            ->setTradeId('exchange-trade-1')
            ->setOrderId('exchange-order-1')
            ->setSymbol('BTCUSDT')
            ->setSide(1)
            ->setPrice('100')
            ->setSize(1)
            ->setTradeTime(1);
    }
}
