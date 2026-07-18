<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeOneWayConflictGuard;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeScenarioService;
use App\Exchange\Fake\FakeExchangeStateStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversNothing]
final class FakeOneWayConflictGuardTest extends TestCase
{
    public function testLongPositionRejectsShortBeforeMarginAndPersistsRedactedIdempotentRejection(): void
    {
        $state = new MarginReadFailingFakeExchangeStateStore();
        [$adapter, $scenario] = $this->exchangeForState($state);
        $long = $adapter->placeOrder($this->entryRequest(
            ExchangePositionSide::LONG,
            'one-way-long-open',
            metadata: ['internal_trade_id' => 'trade-long'],
        ));
        $availableMarginBefore = $state->availableMarginUsdt();

        $state->failOnMarginRead = true;
        $shortRequest = $this->entryRequest(
            ExchangePositionSide::SHORT,
            'one-way-short-conflict',
            metadata: [
                'internal_trade_id' => 'trade-short',
                'api_key' => 'TOP-SECRET',
                'raw_payload' => ['authorization' => 'Bearer SECRET'],
            ],
        );
        $rejected = $adapter->placeOrder($shortRequest);
        $replayed = $adapter->placeOrder($shortRequest);
        $state->failOnMarginRead = false;

        self::assertTrue($long->accepted);
        self::assertFalse($rejected->accepted);
        self::assertSame(ExchangeOrderStatus::REJECTED, $rejected->status);
        self::assertSame('one_way_position_conflict', $rejected->metadata['reason'] ?? null);
        self::assertSame('fake-one-way-v1', $rejected->metadata['position_mode_version'] ?? null);
        self::assertSame('fake::perpetual::BTCUSDT', $rejected->metadata['position_scope'] ?? null);
        self::assertSame('short', $rejected->metadata['requested_position_side'] ?? null);
        self::assertSame('long', $rejected->metadata['conflicting_position_side'] ?? null);
        self::assertSame('open_position', $rejected->metadata['conflict_source'] ?? null);
        self::assertSame($rejected->exchangeOrderId, $replayed->exchangeOrderId);
        self::assertTrue($replayed->metadata['idempotent_replay'] ?? false);
        self::assertSame($availableMarginBefore, $state->availableMarginUsdt());
        self::assertCount(1, $adapter->getOpenPositions('BTCUSDT'));
        self::assertSame(ExchangePositionSide::LONG, $adapter->getOpenPositions('BTCUSDT')[0]->side);
        self::assertCount(1, $adapter->getOpenOrders('BTCUSDT'));
        self::assertTrue($adapter->getOpenOrders('BTCUSDT')[0]->reduceOnly);
        self::assertCount(1, $scenario->events('order.rejected'));

        $serialized = json_encode([
            $rejected->metadata,
            array_map(static fn ($event): array => $event->toArray(), $scenario->events('order.rejected')),
        ], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('TOP-SECRET', $serialized);
        self::assertStringNotContainsString('Bearer SECRET', $serialized);
        self::assertStringNotContainsString('api_key', $serialized);
        self::assertStringNotContainsString('raw_payload', $serialized);
    }

    public function testShortPositionRejectsLongEntry(): void
    {
        [$adapter] = $this->exchangeForState(new FakeExchangeStateStore());
        $short = $adapter->placeOrder($this->entryRequest(
            ExchangePositionSide::SHORT,
            'one-way-short-open',
        ));

        $long = $adapter->placeOrder($this->entryRequest(
            ExchangePositionSide::LONG,
            'one-way-long-conflict',
        ));

        self::assertTrue($short->accepted);
        self::assertFalse($long->accepted);
        self::assertSame('one_way_position_conflict', $long->metadata['reason'] ?? null);
        self::assertSame('short', $long->metadata['conflicting_position_side'] ?? null);
        self::assertCount(1, $adapter->getOpenPositions('BTCUSDT'));
        self::assertSame(ExchangePositionSide::SHORT, $adapter->getOpenPositions('BTCUSDT')[0]->side);
    }

    public function testReduceOnlyExitIsAllowedAndFlatPositionAllowsOppositeEntry(): void
    {
        [$adapter] = $this->exchangeForState(new FakeExchangeStateStore());
        $adapter->placeOrder($this->entryRequest(
            ExchangePositionSide::LONG,
            'one-way-long-before-close',
        ));

        $exit = $adapter->placeOrder($this->reduceOnlyRequest(
            ExchangePositionSide::LONG,
            'one-way-long-exit',
        ));
        $opposite = $adapter->placeOrder($this->entryRequest(
            ExchangePositionSide::SHORT,
            'one-way-short-after-flat',
        ));

        self::assertTrue($exit->accepted);
        self::assertSame(ExchangeOrderStatus::FILLED, $exit->status);
        self::assertTrue($exit->order?->reduceOnly);
        self::assertTrue($opposite->accepted);
        self::assertSame(ExchangeOrderStatus::FILLED, $opposite->status);
        self::assertCount(1, $adapter->getOpenPositions('BTCUSDT'));
        self::assertSame(ExchangePositionSide::SHORT, $adapter->getOpenPositions('BTCUSDT')[0]->side);
    }

    public function testActiveOppositeEntryConflictsWithoutAnOpenPosition(): void
    {
        [$adapter] = $this->exchangeForState(new FakeExchangeStateStore());
        $active = $adapter->placeOrder($this->entryRequest(
            ExchangePositionSide::LONG,
            'one-way-active-long',
            orderType: ExchangeOrderType::LIMIT,
            price: 24950.0,
            postOnly: true,
            withProtection: false,
        ));

        $rejected = $adapter->placeOrder($this->entryRequest(
            ExchangePositionSide::SHORT,
            'one-way-short-against-active',
        ));

        self::assertTrue($active->accepted);
        self::assertSame(ExchangeOrderStatus::OPEN, $active->status);
        self::assertFalse($rejected->accepted);
        self::assertSame('one_way_position_conflict', $rejected->metadata['reason'] ?? null);
        self::assertSame('active_order', $rejected->metadata['conflict_source'] ?? null);
        self::assertSame('long', $rejected->metadata['conflicting_position_side'] ?? null);
        self::assertCount(0, $adapter->getOpenPositions('BTCUSDT'));
        self::assertCount(1, $adapter->getOpenOrders('BTCUSDT'));
        self::assertSame($active->exchangeOrderId, $adapter->getOpenOrders('BTCUSDT')[0]->exchangeOrderId);
    }

    public function testRestartPreservesConflictAndRejectedReplay(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_one_way_restart_');
        self::assertNotFalse($stateFile);
        unlink($stateFile);

        try {
            [$adapter] = $this->exchangeForState(new FakeExchangeStateStore($stateFile));
            $adapter->placeOrder($this->entryRequest(
                ExchangePositionSide::LONG,
                'one-way-restart-long',
            ));

            [$restarted, $scenario] = $this->exchangeForState(new FakeExchangeStateStore($stateFile));
            $request = $this->entryRequest(
                ExchangePositionSide::SHORT,
                'one-way-restart-short-conflict',
            );
            $rejected = $restarted->placeOrder($request);

            [$restartedAgain, $scenarioAgain] = $this->exchangeForState(new FakeExchangeStateStore($stateFile));
            $replayed = $restartedAgain->placeOrder($request);

            self::assertFalse($rejected->accepted);
            self::assertSame('one_way_position_conflict', $rejected->metadata['reason'] ?? null);
            self::assertSame($rejected->exchangeOrderId, $replayed->exchangeOrderId);
            self::assertTrue($replayed->metadata['idempotent_replay'] ?? false);
            self::assertCount(1, $scenario->events('order.rejected'));
            self::assertCount(1, $scenarioAgain->events('order.rejected'));
            self::assertCount(1, $restartedAgain->getOpenPositions('BTCUSDT'));
            self::assertSame(ExchangePositionSide::LONG, $restartedAgain->getOpenPositions('BTCUSDT')[0]->side);
        } finally {
            if (is_file($stateFile)) {
                unlink($stateFile);
            }
            if (is_file($stateFile . '.lock')) {
                unlink($stateFile . '.lock');
            }
            foreach (glob($stateFile . '.tmp.*') ?: [] as $temporaryFile) {
                unlink($temporaryFile);
            }
        }
    }

    public function testRestoredMixedCasePositionRejectsOppositeEntry(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_one_way_mixed_position_');
        self::assertNotFalse($stateFile);
        unlink($stateFile);

        try {
            $seeded = new FakeExchangeStateStore($stateFile);
            $seeded->savePosition(new ExchangePositionDto(
                exchange: Exchange::FAKE,
                marketType: MarketType::PERPETUAL,
                symbol: 'bTcUsDt',
                side: ExchangePositionSide::LONG,
                size: 1.0,
                entryPrice: 25000.0,
                markPrice: 25000.0,
                unrealizedPnl: 0.0,
                realizedPnl: 0.0,
                margin: 8333.33,
                leverage: 3.0,
                openedAt: $this->clock()->now(),
                updatedAt: $this->clock()->now(),
            ));

            [$restored] = $this->exchangeForState(new FakeExchangeStateStore($stateFile));
            $rejected = $restored->placeOrder($this->entryRequest(
                ExchangePositionSide::SHORT,
                'one-way-mixed-position-conflict',
            ));

            self::assertFalse($rejected->accepted);
            self::assertSame('one_way_position_conflict', $rejected->metadata['reason'] ?? null);
            self::assertSame('open_position', $rejected->metadata['conflict_source'] ?? null);
            self::assertSame('long', $rejected->metadata['conflicting_position_side'] ?? null);
            self::assertSame('fake::perpetual::BTCUSDT', $rejected->metadata['position_scope'] ?? null);
        } finally {
            $this->removeStateFiles($stateFile);
        }
    }

    public function testRestoredMixedCaseActiveOrderRejectsOppositeEntry(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_one_way_mixed_order_');
        self::assertNotFalse($stateFile);
        unlink($stateFile);

        try {
            $seeded = new FakeExchangeStateStore($stateFile);
            $seeded->saveOrder(new ExchangeOrderDto(
                exchange: Exchange::FAKE,
                marketType: MarketType::PERPETUAL,
                symbol: 'bTcUsDt',
                exchangeOrderId: 'fake-mixed-active-long',
                clientOrderId: 'mixed-active-long',
                side: ExchangeOrderSide::BUY,
                positionSide: ExchangePositionSide::LONG,
                orderType: ExchangeOrderType::LIMIT,
                status: ExchangeOrderStatus::OPEN,
                quantity: 1.0,
                filledQuantity: 0.0,
                remainingQuantity: 1.0,
                price: 24950.0,
                averagePrice: null,
                stopPrice: null,
                reduceOnly: false,
                postOnly: true,
                timeInForce: ExchangeTimeInForce::GTC,
                createdAt: $this->clock()->now(),
                metadata: [
                    'margin_reference_price' => 24950.0,
                    'margin_reference_source' => 'top_of_book',
                    'margin_contract_size' => '1',
                    'leverage' => 3,
                ],
            ));

            [$restored] = $this->exchangeForState(new FakeExchangeStateStore($stateFile));
            $rejected = $restored->placeOrder($this->entryRequest(
                ExchangePositionSide::SHORT,
                'one-way-mixed-order-conflict',
            ));

            self::assertFalse($rejected->accepted);
            self::assertSame('one_way_position_conflict', $rejected->metadata['reason'] ?? null);
            self::assertSame('active_order', $rejected->metadata['conflict_source'] ?? null);
            self::assertSame('long', $rejected->metadata['conflicting_position_side'] ?? null);
            self::assertSame('fake::perpetual::BTCUSDT', $rejected->metadata['position_scope'] ?? null);
        } finally {
            $this->removeStateFiles($stateFile);
        }
    }

    public function testDifferentSymbolsRemainIndependent(): void
    {
        [$adapter] = $this->exchangeForState(new FakeExchangeStateStore());
        $btcLong = $adapter->placeOrder($this->entryRequest(
            ExchangePositionSide::LONG,
            'one-way-btc-long',
        ));
        $ethShort = $adapter->placeOrder($this->entryRequest(
            ExchangePositionSide::SHORT,
            'one-way-eth-short',
            symbol: 'ETHUSDT',
        ));

        self::assertTrue($btcLong->accepted);
        self::assertTrue($ethShort->accepted);
        self::assertCount(1, $adapter->getOpenPositions('BTCUSDT'));
        self::assertCount(1, $adapter->getOpenPositions('ETHUSDT'));
        self::assertSame(ExchangePositionSide::LONG, $adapter->getOpenPositions('BTCUSDT')[0]->side);
        self::assertSame(ExchangePositionSide::SHORT, $adapter->getOpenPositions('ETHUSDT')[0]->side);
    }

    public function testAmbiguousActiveEntryFailsClosedInsteadOfFallingBackToHedge(): void
    {
        $state = new FakeExchangeStateStore();
        $state->saveOrder(new ExchangeOrderDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'fake-legacy-ambiguous',
            clientOrderId: 'legacy-ambiguous-entry',
            side: ExchangeOrderSide::BUY,
            positionSide: null,
            orderType: ExchangeOrderType::LIMIT,
            status: ExchangeOrderStatus::OPEN,
            quantity: 1.0,
            filledQuantity: 0.0,
            remainingQuantity: 1.0,
            price: 24950.0,
            averagePrice: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: true,
            timeInForce: ExchangeTimeInForce::GTC,
            createdAt: $this->clock()->now(),
            metadata: [
                'margin_reference_price' => 24950.0,
                'margin_reference_source' => 'top_of_book',
                'margin_contract_size' => '1',
                'leverage' => 3,
            ],
        ));
        [$adapter] = $this->exchangeForState($state);

        $rejected = $adapter->placeOrder($this->entryRequest(
            ExchangePositionSide::LONG,
            'one-way-ambiguous-active-conflict',
        ));

        self::assertFalse($rejected->accepted);
        self::assertSame('one_way_position_conflict', $rejected->metadata['reason'] ?? null);
        self::assertSame('ambiguous_active_order', $rejected->metadata['conflict_source'] ?? null);
        self::assertArrayNotHasKey('conflicting_position_side', $rejected->metadata);
    }

    public function testGuardScopeIncludesExchangeMarketTypeAndNormalizedSymbol(): void
    {
        $state = new FakeExchangeStateStore();
        $state->savePosition(new ExchangePositionDto(
            exchange: Exchange::BITMART,
            marketType: MarketType::SPOT,
            symbol: 'BTCUSDT',
            side: ExchangePositionSide::SHORT,
            size: 1.0,
            entryPrice: 25000.0,
            markPrice: 25000.0,
            unrealizedPnl: null,
            realizedPnl: null,
            margin: 1.0,
            leverage: 1.0,
        ));

        $metadata = (new FakeOneWayConflictGuard($state))->conflictMetadata(
            $this->entryRequest(ExchangePositionSide::LONG, 'one-way-exact-scope'),
            false,
        );

        self::assertNull($metadata);
    }

    /** @return array{FakeExchangeAdapter,FakeExchangeScenarioService} */
    private function exchangeForState(FakeExchangeStateStore $state): array
    {
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine($state, $book, $this->clock());

        return [
            new FakeExchangeAdapter($state, $book, $engine, $this->clock()),
            new FakeExchangeScenarioService($state, $book, $engine),
        ];
    }

    /** @param array<string,mixed> $metadata */
    private function entryRequest(
        ExchangePositionSide $positionSide,
        string $clientOrderId,
        string $symbol = 'BTCUSDT',
        ExchangeOrderType $orderType = ExchangeOrderType::MARKET,
        ?float $price = null,
        bool $postOnly = false,
        bool $withProtection = true,
        array $metadata = [],
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol,
            side: $positionSide === ExchangePositionSide::LONG
                ? ExchangeOrderSide::BUY
                : ExchangeOrderSide::SELL,
            positionSide: $positionSide,
            orderType: $orderType,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 1.0,
            price: $price,
            stopPrice: null,
            reduceOnly: false,
            postOnly: $postOnly,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: $clientOrderId,
            attachedStopLossPrice: $withProtection
                ? ($positionSide === ExchangePositionSide::SHORT
                    ? ($symbol === 'ETHUSDT' ? 1820.0 : 25200.0)
                    : ($symbol === 'ETHUSDT' ? 1780.0 : 24800.0))
                : null,
            metadata: $metadata,
        );
    }

    private function reduceOnlyRequest(
        ExchangePositionSide $positionSide,
        string $clientOrderId,
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: $positionSide === ExchangePositionSide::LONG
                ? ExchangeOrderSide::SELL
                : ExchangeOrderSide::BUY,
            positionSide: $positionSide,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 1.0,
            price: null,
            stopPrice: null,
            reduceOnly: true,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: $clientOrderId,
        );
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
    }

    private function removeStateFiles(string $stateFile): void
    {
        if (is_file($stateFile)) {
            unlink($stateFile);
        }
        if (is_file($stateFile . '.lock')) {
            unlink($stateFile . '.lock');
        }
        foreach (glob($stateFile . '.tmp.*') ?: [] as $temporaryFile) {
            unlink($temporaryFile);
        }
    }
}

final class MarginReadFailingFakeExchangeStateStore extends FakeExchangeStateStore
{
    public bool $failOnMarginRead = false;

    public function availableMarginUsdt(): float
    {
        if ($this->failOnMarginRead) {
            throw new \LogicException('one_way_conflict_read_margin_before_reject');
        }

        return parent::availableMarginUsdt();
    }
}
