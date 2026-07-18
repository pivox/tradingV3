<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Fake\FakeDailyLossCapGuard;
use App\Exchange\Fake\FakeDailyLossCapPolicy;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeFillCostModel;
use App\Exchange\Fake\FakeFundingModelConfig;
use App\Exchange\Fake\FakeInstrumentCatalog;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversNothing]
final class FakeDailyLossCapGuardTest extends TestCase
{
    public function testBelowCapRemainsComputableAndAllowsExposureIncrease(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T12:00:00+00:00');
        $this->appendFill($state, $clock->now(), '-9.999999999999');

        $status = $this->guard($state, $clock)->current();

        self::assertSame('ready', $status->status);
        self::assertSame('2026-07-18', $status->utcDate);
        self::assertSame('10.000000000000', $status->limitUsdt);
        self::assertSame('-9.999999999999', $status->dailyNetUsdt);
        self::assertSame('9.999999999999', $status->consumptionUsdt);
        self::assertFalse($status->blocksExposureIncrease());
        self::assertNull($status->reason);
    }

    public function testExactCapBlocksWithStableStructuredRedactedIdempotentRejectionBeforeMargin(): void
    {
        $state = new DailyLossMarginReadFailingStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T12:00:00+00:00');
        $this->appendFill($state, $clock->now(), '-10');
        $state->failOnMarginRead = true;
        $adapter = $this->adapter($state, $clock);
        $request = $this->request(
            'daily-cap-exact',
            metadata: [
                'internal_trade_id' => 'trade-safe-lineage',
                'api_key' => 'TOP-SECRET',
                'raw_payload' => ['authorization' => 'Bearer SECRET'],
            ],
        );

        $rejected = $adapter->placeOrder($request);
        $replayed = $adapter->placeOrder($request);

        self::assertFalse($rejected->accepted);
        self::assertSame(ExchangeOrderStatus::REJECTED, $rejected->status);
        self::assertSame('daily_loss_cap_reached', $rejected->metadata['reason'] ?? null);
        self::assertSame('fake-daily-loss-cap-v1', $rejected->metadata['daily_loss_cap_policy_version'] ?? null);
        self::assertSame('2026-07-18', $rejected->metadata['daily_loss_cap_utc_date'] ?? null);
        self::assertSame('limit_reached', $rejected->metadata['daily_loss_cap_status'] ?? null);
        self::assertSame('10.000000000000', $rejected->metadata['daily_loss_cap_limit_usdt'] ?? null);
        self::assertSame('-10.000000000000', $rejected->metadata['daily_loss_cap_daily_net_usdt'] ?? null);
        self::assertSame('10.000000000000', $rejected->metadata['daily_loss_cap_consumption_usdt'] ?? null);
        self::assertSame($rejected->exchangeOrderId, $replayed->exchangeOrderId);
        self::assertTrue($replayed->metadata['idempotent_replay'] ?? false);
        self::assertCount(1, $state->events('order.rejected'));
        self::assertCount(1, $state->getOrders());
        self::assertSame(1, $this->guard($state, $clock)->current()->rejectionCount);

        $serialized = json_encode([
            $rejected->metadata,
            array_map(static fn (FakeExchangeEvent $event): array => $event->toArray(), $state->events('order.rejected')),
        ], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('TOP-SECRET', $serialized);
        self::assertStringNotContainsString('Bearer SECRET', $serialized);
        self::assertStringNotContainsString('api_key', $serialized);
        self::assertStringNotContainsString('raw_payload', $serialized);
    }

    public function testRealizedPnlAloneCanExceedCap(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T13:00:00+00:00');
        $this->appendFill($state, $clock->now(), '-10.000000000001');

        $status = $this->guard($state, $clock)->current();

        self::assertSame('limit_reached', $status->status);
        self::assertSame('daily_loss_cap_reached', $status->reason);
        self::assertSame('10.000000000001', $status->consumptionUsdt);
    }

    public function testFeesAndNegativeFundingCanExceedCapWhilePositiveFundingKeepsItsCreditSign(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T14:00:00+00:00');
        $this->appendFill($state, $clock->now(), null, fee: '1.25', reduceOnly: false);
        $this->appendFunding($state, $clock->now(), '-9');
        $this->appendFunding($state, $clock->now(), '0.10', sequence: 3);

        $status = $this->guard($state, $clock)->current();

        self::assertSame('limit_reached', $status->status);
        self::assertSame('-10.150000000000', $status->dailyNetUsdt);
        self::assertSame('10.150000000000', $status->consumptionUsdt);
    }

    public function testSpreadAndSlippageCostsParticipateInExactCap(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T14:30:00+00:00');
        $this->appendFill(
            $state,
            $clock->now(),
            gross: '0',
            spread: '4',
            slippage: '6',
        );

        $status = $this->guard($state, $clock)->current();

        self::assertSame('-10.000000000000', $status->dailyNetUsdt);
        self::assertSame('10.000000000000', $status->consumptionUsdt);
        self::assertSame('limit_reached', $status->status);
    }

    public function testUnknownNecessaryCostIsNeverConvertedToZeroAndBlocksFailClosed(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T15:00:00+00:00');
        $this->appendFill($state, $clock->now(), '-1', fee: null);

        $status = $this->guard($state, $clock)->current();

        self::assertSame('not_computable', $status->status);
        self::assertSame('daily_loss_cap_not_computable', $status->reason);
        self::assertSame('fill_fee_unknown', $status->detailReason);
        self::assertNull($status->dailyNetUsdt);
        self::assertNull($status->consumptionUsdt);
        self::assertTrue($status->blocksExposureIncrease());
    }

    public function testNotComputableExposureIncreasePersistsStableStructuredRejection(): void
    {
        $state = new DailyLossMarginReadFailingStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T15:30:00+00:00');
        $this->appendFill($state, $clock->now(), '-1', fee: null);
        $state->failOnMarginRead = true;

        $rejected = $this->adapter($state, $clock)->placeOrder($this->request('daily-cap-not-computable'));

        self::assertFalse($rejected->accepted);
        self::assertSame('daily_loss_cap_not_computable', $rejected->metadata['reason'] ?? null);
        self::assertSame('not_computable', $rejected->metadata['daily_loss_cap_status'] ?? null);
        self::assertSame('fill_fee_unknown', $rejected->metadata['daily_loss_cap_detail_reason'] ?? null);
        self::assertArrayHasKey('daily_loss_cap_daily_net_usdt', $rejected->metadata);
        self::assertNull($rejected->metadata['daily_loss_cap_daily_net_usdt']);
        self::assertArrayHasKey('daily_loss_cap_consumption_usdt', $rejected->metadata);
        self::assertNull($rejected->metadata['daily_loss_cap_consumption_usdt']);
        self::assertCount(1, $state->events('order.rejected'));
    }

    public function testUnknownFundingConversionFailsClosed(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T15:45:00+00:00');
        $this->appendFunding($state, $clock->now(), null);

        $status = $this->guard($state, $clock)->current();

        self::assertSame('not_computable', $status->status);
        self::assertSame('funding_amount_usdt_unknown', $status->detailReason);
        self::assertNull($status->consumptionUsdt);
    }

    public function testConflictingNativeAndNormalizedUsdtFundingSignsFailClosed(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T15:47:00+00:00');
        $this->appendFunding($state, $clock->now(), '1', nativeAmount: '-1');

        $status = $this->guard($state, $clock)->current();

        self::assertSame('not_computable', $status->status);
        self::assertSame('funding_amount_usdt_conflict', $status->detailReason);
    }

    public function testProfitableDayConsumptionFloorsAtExactZero(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T15:50:00+00:00');
        $this->appendFill($state, $clock->now(), '10', fee: '1');

        $status = $this->guard($state, $clock)->current();

        self::assertSame('9.000000000000', $status->dailyNetUsdt);
        self::assertSame('0.000000000000', $status->consumptionUsdt);
        self::assertSame('ready', $status->status);
    }

    public function testNegativeTradingCostIsInvalidRatherThanAProfit(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T15:55:00+00:00');
        $this->appendFill($state, $clock->now(), '0', fee: '-1');

        $status = $this->guard($state, $clock)->current();

        self::assertSame('not_computable', $status->status);
        self::assertSame('fill_fee_invalid', $status->detailReason);
    }

    #[DataProvider('riskReductionRequests')]
    public function testRiskReducingAndProtectionRequestsRemainAllowedWhenStateIsNotComputable(
        ExchangeOrderType $type,
        bool $reduceOnly,
    ): void {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T16:00:00+00:00');
        $this->appendFill($state, $clock->now(), '-1', fee: null);
        $guard = $this->guard($state, $clock);

        $metadata = $guard->rejectionMetadata($this->request(
            'daily-cap-reduction-' . $type->value,
            type: $type,
            reduceOnly: $reduceOnly,
        ));

        self::assertNull($metadata);
    }

    /** @return iterable<string,array{ExchangeOrderType,bool}> */
    public static function riskReductionRequests(): iterable
    {
        yield 'emergency reduce-only market close' => [ExchangeOrderType::MARKET, true];
        yield 'standalone stop loss' => [ExchangeOrderType::STOP_LOSS, false];
        yield 'standalone take profit' => [ExchangeOrderType::TAKE_PROFIT, false];
        yield 'standalone trigger close' => [ExchangeOrderType::TRIGGER, false];
    }

    public function testReduceOnlyOrderStillExecutesWhenCapIsNotComputable(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T16:15:00+00:00');
        $this->appendFill($state, $clock->now(), '-1', fee: null);
        $this->savePosition($state, ExchangePositionSide::LONG, 1.0);

        $result = $this->adapter($state, $clock)->placeOrder($this->request(
            'daily-cap-executable-reduction',
            reduceOnly: true,
            quantity: 1.0,
        ));

        self::assertTrue($result->accepted);
        self::assertSame(ExchangeOrderStatus::FILLED, $result->status);
        self::assertNull($state->getPosition('BTCUSDT', ExchangePositionSide::LONG));
    }

    #[DataProvider('standaloneProtectionTypes')]
    public function testStandaloneProtectionStillCreatesReduceOnlyOrderWhenCapIsNotComputable(
        ExchangeOrderType $type,
    ): void {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T16:30:00+00:00');
        $this->appendFill($state, $clock->now(), '-1', fee: null);
        $this->savePosition($state, ExchangePositionSide::LONG, 1.0);

        $result = $this->adapter($state, $clock)->placeOrder($this->request(
            'daily-cap-protection-' . $type->value,
            type: $type,
            quantity: 1.0,
        ));

        self::assertTrue($result->accepted);
        self::assertSame(ExchangeOrderStatus::OPEN, $result->status);
        self::assertTrue($result->order?->reduceOnly);
    }

    /** @return iterable<string,array{ExchangeOrderType}> */
    public static function standaloneProtectionTypes(): iterable
    {
        yield 'stop loss' => [ExchangeOrderType::STOP_LOSS];
        yield 'take profit' => [ExchangeOrderType::TAKE_PROFIT];
    }

    public function testUtcMidnightChangesOnlyTheWindowAndKeepsHistory(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T23:59:59.999999+00:00');
        $this->appendFill($state, $clock->now(), '-10');
        $guard = $this->guard($state, $clock);

        self::assertSame('limit_reached', $guard->current()->status);

        $clock->set('2026-07-19T00:00:00+00:00');
        $nextDay = $guard->current();

        self::assertSame('ready', $nextDay->status);
        self::assertSame('2026-07-19', $nextDay->utcDate);
        self::assertSame('0.000000000000', $nextDay->dailyNetUsdt);
        self::assertSame('0.000000000000', $nextDay->consumptionUsdt);
        self::assertCount(1, $state->events('order.filled'));
    }

    public function testRestartReconstructsTheSameStatusAndRejectedReplayFromPersistentEvents(): void
    {
        $stateFile = $this->stateFile();
        $clock = new MutableDailyLossClock('2026-07-18T17:00:00+00:00');

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $this->appendFill($state, $clock->now(), '-10');
            $first = $this->adapter($state, $clock)->placeOrder($this->request('daily-cap-persistent-reject'));

            $restored = new FakeExchangeStateStore($stateFile);
            $restoredGuard = $this->guard($restored, $clock);
            $replayed = $this->adapter($restored, $clock)->placeOrder($this->request('daily-cap-persistent-reject'));

            self::assertSame('limit_reached', $restoredGuard->current()->status);
            self::assertSame('10.000000000000', $restoredGuard->current()->consumptionUsdt);
            self::assertSame($first->exchangeOrderId, $replayed->exchangeOrderId);
            self::assertTrue($replayed->metadata['idempotent_replay'] ?? false);
            self::assertCount(1, $restored->events('order.rejected'));
        } finally {
            $this->removeStateFiles($stateFile);
        }
    }

    public function testExactDuplicateMonetaryEventIsCountedOnce(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T18:00:00+00:00');
        $this->appendFill($state, $clock->now(), '-6', sequence: 7);
        $this->appendFill($state, $clock->now(), '-6', sequence: 7);

        $status = $this->guard($state, $clock)->current();

        self::assertSame('ready', $status->status);
        self::assertSame('6.000000000000', $status->consumptionUsdt);
        self::assertSame(1, $status->monetaryEventCount);
        self::assertSame(1, $status->duplicateEventCount);
    }

    public function testConflictingDuplicateSequenceIsNotComputable(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T18:30:00+00:00');
        $this->appendFill($state, $clock->now(), '-6', sequence: 8);
        $this->appendFill($state, $clock->now(), '-7', sequence: 8);

        $status = $this->guard($state, $clock)->current();

        self::assertSame('not_computable', $status->status);
        self::assertSame('conflicting_event_sequence', $status->detailReason);
        self::assertNull($status->consumptionUsdt);
    }

    public function testDecimalAdditionAndFundingSignsAreExact(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T19:00:00+00:00');
        $this->appendFill($state, $clock->now(), null, fee: '0.1', reduceOnly: false);
        $this->appendFunding($state, $clock->now(), '-0.2');

        $status = $this->guard($state, $clock, '0.3')->current();

        self::assertSame('-0.300000000000', $status->dailyNetUsdt);
        self::assertSame('0.300000000000', $status->consumptionUsdt);
        self::assertSame('limit_reached', $status->status);
    }

    public function testFutureMonetaryFactFailsClosed(): void
    {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T19:00:00+00:00');
        $this->appendFill($state, new \DateTimeImmutable('2026-07-18T19:00:01+00:00'), '-1');

        $status = $this->guard($state, $clock)->current();

        self::assertSame('not_computable', $status->status);
        self::assertSame('future_monetary_event', $status->detailReason);
    }

    public function testUnreadablePersistentEventLedgerFailsClosedWithoutLeakingException(): void
    {
        $status = $this->guard(
            new DailyLossEventReadFailingStateStore(),
            new MutableDailyLossClock('2026-07-18T19:30:00+00:00'),
        )->current();

        self::assertSame('not_computable', $status->status);
        self::assertSame('daily_loss_cap_not_computable', $status->reason);
        self::assertSame('state_event_ledger_unavailable', $status->detailReason);
        self::assertNull($status->consumptionUsdt);
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidLimitFailsClosedWithoutThrowing(string $limit): void
    {
        $status = $this->guard(
            new FakeExchangeStateStore(),
            new MutableDailyLossClock('2026-07-18T20:00:00+00:00'),
            $limit,
        )->current();

        self::assertSame('not_computable', $status->status);
        self::assertSame('daily_loss_cap_not_computable', $status->reason);
        self::assertSame('invalid_daily_loss_cap_limit', $status->detailReason);
        self::assertNull($status->limitUsdt);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidLimits(): iterable
    {
        yield 'blank' => [''];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'exponent' => ['1e2'];
        yield 'explicit plus' => ['+1'];
        yield 'non numeric' => ['NaN'];
        yield 'too many integer digits' => ['1234567890123456789'];
        yield 'too many fraction digits' => ['0.1234567890123'];
    }

    #[DataProvider('realizedSides')]
    public function testEnginePersistsSignedScaleTwelveRealizedGrossForPartialReduction(
        ExchangePositionSide $positionSide,
        string $expectedDirection,
    ): void {
        $state = new FakeExchangeStateStore();
        $clock = new MutableDailyLossClock('2026-07-18T21:00:00+00:00');
        $this->savePosition($state, $positionSide, 1.0);
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine($state, $book, $clock);
        $request = $this->request(
            'daily-cap-realized-' . $positionSide->value,
            reduceOnly: true,
            quantity: 0.5,
            positionSide: $positionSide,
        );

        $result = $engine->submit($request);
        $fill = $state->events('order.filled')[0] ?? null;

        self::assertTrue($result->accepted);
        self::assertInstanceOf(FakeExchangeEvent::class, $fill);
        $fillPrice = $fill->payload['fill_price'] ?? null;
        self::assertIsFloat($fillPrice);
        $instrument = (new FakeInstrumentCatalog())->find('BTCUSDT');
        self::assertNotNull($instrument);
        $priceDelta = $positionSide === ExchangePositionSide::LONG
            ? BigDecimal::of(json_encode($fillPrice, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR))->minus('25000')
            : BigDecimal::of('25000')->minus(json_encode($fillPrice, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $expected = (string) $priceDelta
            ->multipliedBy('0.5')
            ->multipliedBy($instrument->contractSize)
            ->toScale(12, RoundingMode::HALF_EVEN);

        self::assertSame($expected, $fill->payload['realized_gross_pnl_usdt'] ?? null);
        self::assertSame($expectedDirection, str_starts_with($expected, '-') ? 'loss' : 'profit');
    }

    /** @return iterable<string,array{ExchangePositionSide,string}> */
    public static function realizedSides(): iterable
    {
        yield 'long sold below entry' => [ExchangePositionSide::LONG, 'loss'];
        yield 'short bought above entry' => [ExchangePositionSide::SHORT, 'loss'];
    }

    private function guard(
        FakeExchangeStateStore $state,
        ClockInterface $clock,
        string $limit = '10',
    ): FakeDailyLossCapGuard {
        return new FakeDailyLossCapGuard($state, $clock, new FakeDailyLossCapPolicy($limit));
    }

    private function adapter(
        FakeExchangeStateStore $state,
        ClockInterface $clock,
        string $limit = '10',
    ): FakeExchangeAdapter {
        $book = new FakeExchangeOrderBook($state);
        $guard = $this->guard($state, $clock, $limit);
        $engine = new FakeExchangeMatchingEngine(
            $state,
            $book,
            $clock,
            dailyLossCapGuard: $guard,
        );

        return new FakeExchangeAdapter($state, $book, $engine, $clock);
    }

    /** @param array<string,mixed> $metadata */
    private function request(
        string $clientOrderId,
        ExchangeOrderType $type = ExchangeOrderType::MARKET,
        bool $reduceOnly = false,
        float $quantity = 1.0,
        ExchangePositionSide $positionSide = ExchangePositionSide::LONG,
        array $metadata = [],
    ): PlaceOrderRequest {
        $reduceIntent = $reduceOnly || \in_array($type, [
            ExchangeOrderType::STOP_LOSS,
            ExchangeOrderType::TAKE_PROFIT,
            ExchangeOrderType::TRIGGER,
        ], true);

        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: match ([$reduceIntent, $positionSide]) {
                [false, ExchangePositionSide::LONG], [true, ExchangePositionSide::SHORT] => ExchangeOrderSide::BUY,
                [false, ExchangePositionSide::SHORT], [true, ExchangePositionSide::LONG] => ExchangeOrderSide::SELL,
            },
            positionSide: $positionSide,
            orderType: $type,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: $quantity,
            price: null,
            stopPrice: \in_array($type, [
                ExchangeOrderType::STOP_LOSS,
                ExchangeOrderType::TAKE_PROFIT,
                ExchangeOrderType::TRIGGER,
            ], true) ? 24900.0 : null,
            reduceOnly: $reduceOnly,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: $clientOrderId,
            metadata: $metadata,
        );
    }

    private function appendFill(
        FakeExchangeStateStore $state,
        \DateTimeImmutable $occurredAt,
        ?string $gross,
        ?string $fee = '0',
        string $spread = '0',
        string $slippage = '0',
        bool $reduceOnly = true,
        ?int $sequence = null,
    ): void {
        $payload = [
            'fill_quantity' => '1.000000000000',
            'fill_price' => '100.000000000000',
            'fill_fee' => $fee,
            'fee_currency' => 'USDT',
            'liquidity_role' => 'taker',
            'spread_cost_usdt' => $spread,
            'slippage_cost_usdt' => $slippage,
            'cost_model_version' => FakeFillCostModel::MODEL_VERSION,
            'spread_model_version' => FakeFillCostModel::SPREAD_MODEL_VERSION,
            'pnl_source' => 'fake_paper_fill_ledger_v1',
            'cost_completeness' => 'complete',
            'realized_gross_pnl_usdt' => $gross,
            'order_snapshot' => ['reduce_only' => $reduceOnly],
        ];
        if ($sequence !== null) {
            $payload = ['event_sequence' => $sequence] + $payload;
        }
        $state->appendEvent(new FakeExchangeEvent('order.filled', 'BTCUSDT', $occurredAt, $payload));
    }

    private function appendFunding(
        FakeExchangeStateStore $state,
        \DateTimeImmutable $occurredAt,
        ?string $amountUsdt,
        ?int $sequence = null,
        ?string $nativeAmount = null,
    ): void {
        $payload = [
            'amount' => $nativeAmount ?? $amountUsdt,
            'currency' => 'USDT',
            'amount_usdt' => $amountUsdt,
            'due_at' => $occurredAt->format(\DateTimeInterface::ATOM),
            'model_version' => FakeFundingModelConfig::MODEL_VERSION,
            'funding_idempotency_key' => 'funding-' . ($sequence ?? \count($state->events()) + 1),
            'funding_payload_hash' => hash('sha256', (string) $amountUsdt . ':' . ($sequence ?? \count($state->events()) + 1)),
        ];
        if ($sequence !== null) {
            $payload = ['event_sequence' => $sequence] + $payload;
        }
        $state->appendEvent(new FakeExchangeEvent('funding.accrued', 'BTCUSDT', $occurredAt, $payload));
    }

    private function savePosition(
        FakeExchangeStateStore $state,
        ExchangePositionSide $side,
        float $size,
    ): void {
        $state->savePosition(new ExchangePositionDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: $side,
            size: $size,
            entryPrice: 25000.0,
            markPrice: 25000.0,
            unrealizedPnl: 0.0,
            realizedPnl: 0.0,
            margin: 100.0,
            leverage: 3.0,
            openedAt: new \DateTimeImmutable('2026-07-18T10:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-07-18T10:00:00+00:00'),
            metadata: [
                'source' => 'fake_exchange',
                'entry_qty' => $size,
                'entry_notional_usdt' => $size * 25000.0,
                'entry_fee_usdt' => 0.0,
                'entry_spread_cost_usdt' => 0.0,
                'entry_slippage_cost_usdt' => 0.0,
                'entry_order_count' => 1,
                'cost_model_version' => FakeFillCostModel::MODEL_VERSION,
                'spread_model_version' => FakeFillCostModel::SPREAD_MODEL_VERSION,
                'pnl_source' => 'fake_paper_fill_ledger_v1',
            ],
        ));
    }

    private function stateFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fake_daily_loss_');
        self::assertIsString($path);
        unlink($path);

        return $path;
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

final class MutableDailyLossClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $now)
    {
        $this->set($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(string $now): void
    {
        $this->now = new \DateTimeImmutable($now);
    }
}

final class DailyLossMarginReadFailingStateStore extends FakeExchangeStateStore
{
    public bool $failOnMarginRead = false;

    public function availableMarginUsdt(): float
    {
        if ($this->failOnMarginRead) {
            throw new \LogicException('daily_loss_cap_read_margin_before_reject');
        }

        return parent::availableMarginUsdt();
    }
}

final class DailyLossEventReadFailingStateStore extends FakeExchangeStateStore
{
    public function events(?string $type = null): array
    {
        throw new \LogicException('state event payload token=runtime-secret');
    }
}
