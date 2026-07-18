<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Event;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeFundingReceived;
use App\Exchange\Event\ExchangeOrderCreated;
use App\Exchange\Event\ExchangeOrderFilled;
use App\Exchange\Event\ExchangeOrderPartiallyFilled;
use App\Exchange\Event\ExchangePositionClosed;
use App\Exchange\Event\ExchangePositionOpened;
use App\Exchange\Event\ExchangeProtectionOrderRejected;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeScenarioService;
use App\Exchange\Fake\FakeExchangeStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(FakeExchangeEventNormalizer::class)]
#[CoversClass(FakeExchangeEvent::class)]
#[CoversClass(FakeExchangeStateStore::class)]
#[CoversClass(FakeExchangeMatchingEngine::class)]
#[CoversClass(FakeExchangeOrderBook::class)]
#[CoversClass(FakeExchangeScenarioService::class)]
#[CoversClass(ExchangeOrderPartiallyFilled::class)]
#[CoversClass(ExchangeOrderCreated::class)]
#[CoversClass(ExchangeFillReceived::class)]
#[CoversClass(ExchangePositionClosed::class)]
#[CoversClass(ExchangePositionOpened::class)]
#[CoversClass(ExchangeProtectionOrderRejected::class)]
final class FakeExchangeEventNormalizerTest extends TestCase
{
    private FakeExchangeStateStore $state;
    private FakeExchangeScenarioService $scenario;
    private FakeExchangeEventNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->state = new FakeExchangeStateStore();
        $book = new FakeExchangeOrderBook($this->state);
        $engine = new FakeExchangeMatchingEngine($this->state, $book, $this->fixedClock());
        $this->scenario = new FakeExchangeScenarioService($this->state, $book, $engine);
        $this->normalizer = new FakeExchangeEventNormalizer();
    }

    public function testNormalizesPartialFillIntoOrderAndFillEvents(): void
    {
        $placed = $this->scenarioAdapter()->placeOrder($this->request(price: 24950.0, quantity: 10.0, postOnly: true));
        $this->scenario->fillOrder((string)$placed->exchangeOrderId, 4.0, 24950.0);

        $events = $this->scenario->events('order.partially_filled');
        $normalized = $this->normalizer->normalize($events[0]);

        self::assertCount(2, $normalized);
        self::assertInstanceOf(ExchangeOrderPartiallyFilled::class, $normalized[0]);
        self::assertInstanceOf(ExchangeFillReceived::class, $normalized[1]);
        self::assertSame('exchange.order.partially_filled', $normalized[0]->eventType());
        self::assertEqualsWithDelta(4.0, $normalized[1]->fill()->quantity, 0.000001);
        self::assertSame('USDT', $normalized[1]->fill()->feeCurrency);
        self::assertNotNull($normalized[1]->fill()->fee);
        self::assertSame('fake_paper_fill_ledger_v1', $normalized[1]->fill()->metadata['pnl_source'] ?? null);
        self::assertSame('maker', $normalized[1]->fill()->metadata['liquidity_role'] ?? null);
        self::assertSame(0.0, $normalized[1]->fill()->metadata['spread_cost_usdt'] ?? null);
        self::assertSame(0.0, $normalized[1]->fill()->metadata['slippage_cost_usdt'] ?? null);
        self::assertSame(
            'fixed_adverse_slippage_bps_v1',
            $normalized[1]->fill()->metadata['cost_model_version'] ?? null,
        );
        self::assertSame(
            'top_of_book_embedded_spread_v1',
            $normalized[1]->fill()->metadata['spread_model_version'] ?? null,
        );
        self::assertSame('BTCUSDT', $normalized[1]->symbol());
        self::assertSame(
            $normalized[1]->fill()->fillId,
            $this->scenarioAdapter()->getFillsSnapshot('BTCUSDT')[0]->fillId,
        );
    }

    public function testNormalizesTakerFillWithExplicitSlippageCosts(): void
    {
        $this->scenarioAdapter()->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            quantity: 2.0,
            postOnly: false,
        ));

        $events = $this->scenario->events('order.filled');
        $normalized = $this->normalizer->normalize($events[0]);

        self::assertCount(2, $normalized);
        self::assertInstanceOf(ExchangeFillReceived::class, $normalized[1]);
        $fill = $normalized[1]->fill();
        self::assertSame('taker', $fill->metadata['liquidity_role'] ?? null);
        self::assertSame(0.0, $fill->metadata['spread_cost_usdt'] ?? null);
        self::assertEqualsWithDelta(
            $fill->quantity * $fill->price * 5.0 / 10_000.0,
            $fill->metadata['slippage_cost_usdt'] ?? null,
            0.000000000001,
        );
        self::assertSame('fixed_adverse_slippage_bps_v1', $fill->metadata['cost_model_version'] ?? null);
        self::assertSame(
            'top_of_book_embedded_spread_v1',
            $fill->metadata['spread_model_version'] ?? null,
        );
    }

    public function testNormalizesPositionOpenedEvent(): void
    {
        $this->scenarioAdapter()->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            quantity: 10.0,
            postOnly: false,
        ));

        $events = $this->scenario->events('position.opened');
        $normalized = $this->normalizer->normalize($events[0]);

        self::assertCount(1, $normalized);
        self::assertInstanceOf(ExchangePositionOpened::class, $normalized[0]);
        self::assertSame('exchange.position.opened', $normalized[0]->eventType());
        self::assertEqualsWithDelta(10.0, $normalized[0]->size(), 0.000001);
    }

    public function testNormalizesFundingIntoOneCostEventAndNoFill(): void
    {
        $event = new FakeExchangeEvent(
            'funding.accrued',
            'BTCUSDT',
            new \DateTimeImmutable('2026-01-01T08:00:00+00:00'),
            [
                'exchange' => 'fake',
                'market_type' => 'perpetual',
                'position_id' => 'fake-position-long',
                'internal_trade_id' => 'itd-funding-normalized',
                'internal_position_id' => 'ipos-funding-normalized',
                'position_side' => 'long',
                'notional' => '20000.000000000000',
                'funding_rate' => '0.0001',
                'rate_interval_seconds' => 28800,
                'applied_interval_seconds' => 28800,
                'amount' => '-2.000000000000',
                'currency' => 'USDT',
                'amount_usdt' => '-2.000000000000',
                'due_at' => '2026-01-01T08:00:00+00:00',
                'source' => 'fake_funding_model',
                'model_version' => 'fake-funding-notional-rate-interval-v1',
                'metadata' => ['position_opened_at' => '2026-01-01T00:00:00+00:00'],
                'funding_idempotency_key' => 'fake:perpetual:funding:identity',
                'funding_payload_hash' => str_repeat('a', 64),
                'event_sequence' => 9,
            ],
        );

        $normalized = $this->normalizer->normalize($event);

        self::assertCount(1, $normalized);
        self::assertInstanceOf(ExchangeFundingReceived::class, $normalized[0]);
        self::assertNotInstanceOf(ExchangeFillReceived::class, $normalized[0]);
        self::assertSame('exchange.funding.received', $normalized[0]->eventType());
        self::assertSame('-2.000000000000', $normalized[0]->funding()->amountUsdt);
        self::assertSame('fake-position-long', $normalized[0]->funding()->positionId);
        self::assertSame('itd-funding-normalized', $normalized[0]->funding()->internalTradeId);
        self::assertSame('2026-01-01T08:00:00+00:00', $normalized[0]->funding()->dueAt->format(\DateTimeInterface::ATOM));
        self::assertSame('fake-funding-notional-rate-interval-v1', $normalized[0]->funding()->modelVersion);
    }

    public function testMalformedFundingPayloadDoesNotBecomeZero(): void
    {
        $event = new FakeExchangeEvent(
            'funding.accrued',
            'BTCUSDT',
            new \DateTimeImmutable('2026-01-01T08:00:00+00:00'),
            [
                'position_id' => 'fake-position-long',
                'amount' => null,
                'currency' => 'USDT',
            ],
        );

        self::assertSame([], $this->normalizer->normalize($event));
    }

    public function testNormalizesPositionClosedWithCertifiedFakePnlEvidence(): void
    {
        $this->scenarioAdapter()->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            quantity: 10.0,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
            metadata: [
                'internal_trade_id' => 'itd-normalized-close',
                'position_id' => 'fake-normalized-pos',
            ],
        ));
        $this->scenario->movePrice('BTCUSDT', 24790.0, 0.0);

        $events = $this->scenario->events('position.closed');
        $normalized = $this->normalizer->normalize($events[0]);

        self::assertCount(1, $normalized);
        self::assertInstanceOf(ExchangePositionClosed::class, $normalized[0]);
        self::assertSame('exchange.position.closed', $normalized[0]->eventType());
        self::assertSame('fake_paper_fill_ledger_v1', $normalized[0]->payload()['pnl_source'] ?? null);
        self::assertSame(true, $normalized[0]->payload()['position_fully_closed'] ?? null);
        self::assertSame('itd-normalized-close', $normalized[0]->payload()['internal_trade_id'] ?? null);
        self::assertSame('fake-normalized-pos', $normalized[0]->payload()['position_id'] ?? null);
        self::assertArrayHasKey('gross_realized_pnl_usdt', $normalized[0]->payload());
        self::assertArrayHasKey('entry_fee_usdt', $normalized[0]->payload());
        self::assertArrayHasKey('exit_fee_usdt', $normalized[0]->payload());
    }

    public function testNormalizesHistoricalOrderCreatedWithOriginalSnapshot(): void
    {
        $this->scenarioAdapter()->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            quantity: 10.0,
            postOnly: false,
        ));

        $events = $this->scenario->events('order.created');
        $normalized = $this->normalizer->normalize($events[0]);

        self::assertCount(1, $normalized);
        self::assertInstanceOf(ExchangeOrderCreated::class, $normalized[0]);
        self::assertSame(ExchangeOrderStatus::OPEN, $normalized[0]->order()->status);
        self::assertEqualsWithDelta(0.0, $normalized[0]->order()->filledQuantity, 0.000001);
    }

    public function testNormalizesRejectedProtectionCompensationLifecycle(): void
    {
        $this->scenario->rejectNextProtectionOrder();
        $this->scenarioAdapter()->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            quantity: 10.0,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
            metadata: ['internal_trade_id' => 'itd-normalized-compensation'],
        ));

        $rejectionEvents = $this->scenario->events('protection_order.rejected');
        $normalizedRejection = $this->normalizer->normalize($rejectionEvents[0]);

        self::assertCount(1, $normalizedRejection);
        self::assertInstanceOf(ExchangeProtectionOrderRejected::class, $normalizedRejection[0]);
        self::assertSame('exchange.protection_order.rejected', $normalizedRejection[0]->eventType());
        self::assertSame('rejected', $normalizedRejection[0]->order()->metadata['protection_status'] ?? null);

        $fillEvents = $this->scenario->events('order.filled');
        self::assertCount(2, $fillEvents);
        $normalizedCompensation = $this->normalizer->normalize($fillEvents[1]);

        self::assertCount(2, $normalizedCompensation);
        self::assertInstanceOf(ExchangeOrderFilled::class, $normalizedCompensation[0]);
        self::assertInstanceOf(ExchangeFillReceived::class, $normalizedCompensation[1]);
        self::assertTrue($normalizedCompensation[0]->order()->reduceOnly);
        self::assertSame(ExchangeOrderType::MARKET, $normalizedCompensation[0]->order()->orderType);
        self::assertSame(
            'itd-normalized-compensation',
            $normalizedCompensation[0]->order()->metadata['internal_trade_id'] ?? null,
        );

        $closedEvents = $this->scenario->events('position.closed');
        self::assertCount(1, $closedEvents);
        $normalizedClose = $this->normalizer->normalize($closedEvents[0]);
        self::assertCount(1, $normalizedClose);
        self::assertInstanceOf(ExchangePositionClosed::class, $normalizedClose[0]);
        self::assertSame(
            'itd-normalized-compensation',
            $normalizedClose[0]->payload()['internal_trade_id'] ?? null,
        );
    }

    private function scenarioAdapter(): \App\Exchange\Adapter\FakeExchangeAdapter
    {
        $book = new FakeExchangeOrderBook($this->state);
        $engine = new FakeExchangeMatchingEngine($this->state, $book, $this->fixedClock());

        return new \App\Exchange\Adapter\FakeExchangeAdapter($this->state, $book, $engine, $this->fixedClock());
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function request(
        ExchangeOrderType $orderType = ExchangeOrderType::LIMIT,
        ?float $price = 24950.0,
        float $quantity = 1.0,
        bool $postOnly = false,
        ?float $attachedStopLossPrice = null,
        array $metadata = [],
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: $orderType,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: $quantity,
            price: $price,
            stopPrice: null,
            reduceOnly: false,
            postOnly: $postOnly,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'cid-1',
            attachedStopLossPrice: $attachedStopLossPrice,
            metadata: $metadata,
        );
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
            }
        };
    }
}
