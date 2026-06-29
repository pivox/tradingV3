<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Okx\Lifecycle\OkxLifecycleNormalizer;
use App\Exchange\Okx\Lifecycle\OkxLifecycleStatus;
use App\Exchange\Okx\OkxActionFactory;
use App\Exchange\Okx\OkxInstrumentResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxLifecycleNormalizer::class)]
#[CoversClass(OkxLifecycleStatus::class)]
#[CoversClass(OkxActionFactory::class)]
#[CoversClass(OkxInstrumentResolver::class)]
final class OkxLifecycleNormalizerTest extends TestCase
{
    private OkxLifecycleNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new OkxLifecycleNormalizer(new OkxInstrumentResolver(), new OkxActionFactory());
    }

    public function testNormalizesOrderRequestWithoutSendingIt(): void
    {
        $request = new PlaceOrderRequest(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 0.01,
            price: 25000.0,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'OKXREQ1',
        );

        $normalized = $this->normalizer->normalizeOrderRequest($request);

        self::assertSame('BTC-USDT-SWAP', $normalized['instId']);
        self::assertSame('OKXREQ1', $normalized['clOrdId']);
        self::assertSame('limit', $normalized['ordType']);
        self::assertSame('25000', $normalized['px']);
        self::assertArrayNotHasKey('apiKey', $normalized);
    }

    public function testNormalizesFilledLifecycleWithAverageFillPrice(): void
    {
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $this->orderRow(state: 'live', filled: '0', updatedAt: '1767225600000'),
            $this->orderRow(state: 'filled', filled: '1', avgPx: '25011.5', updatedAt: '1767225603000'),
        ]);

        self::assertSame(OkxLifecycleStatus::FILLED, $lifecycle->status);
        self::assertSame('order-1', $lifecycle->exchangeOrderId);
        self::assertEqualsWithDelta(1.0, $lifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.0, $lifecycle->remainingQuantity, 0.000001);
        self::assertEqualsWithDelta(25011.5, $lifecycle->averageFillPrice ?? 0.0, 0.000001);
        self::assertSame([], $lifecycle->qualityFlags);
    }

    public function testTreatsSuccessfulPlacementAcknowledgementAsAccepted(): void
    {
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([[
            'clOrdId' => 'client-ack-1',
            'cTime' => '1767225600000',
            'instId' => 'BTC-USDT-SWAP',
            'instType' => 'SWAP',
            'ordId' => 'order-ack-1',
            'sCode' => '0',
            'sMsg' => 'Order placed',
        ]]);

        self::assertSame(OkxLifecycleStatus::ACCEPTED, $lifecycle->status);
        self::assertSame('order-ack-1', $lifecycle->exchangeOrderId);
        self::assertSame('client-ack-1', $lifecycle->clientOrderId);
        self::assertNull($lifecycle->side);
        self::assertFalse($lifecycle->requiresResync);
        self::assertSame([], $lifecycle->qualityFlags);
    }

    public function testNormalizesPartialFillAndFeePerFill(): void
    {
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $this->orderRow(
                state: 'partially_filled',
                filled: '0.4',
                avgPx: '25005',
                fillSz: '0.4',
                fillPx: '25005',
                tradeId: 'trade-1',
                fee: '-0.01',
                updatedAt: '1767225601000',
            ),
        ]);

        self::assertSame(OkxLifecycleStatus::PARTIALLY_FILLED, $lifecycle->status);
        self::assertCount(1, $lifecycle->fills);
        self::assertEqualsWithDelta(0.4, $lifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.6, $lifecycle->remainingQuantity, 0.000001);
        self::assertEqualsWithDelta(-0.01, $lifecycle->fills[0]->fee ?? 0.0, 0.000001);
        self::assertSame('USDT', $lifecycle->fills[0]->feeCurrency);
    }

    public function testNormalizesStandaloneFills(): void
    {
        $fills = $this->normalizer->normalizeFills([
            $this->orderRow(
                state: 'filled',
                filled: '1',
                updatedAt: '1767225601000',
                fillSz: '1',
                fillPx: '25005',
                tradeId: 'standalone-fill',
                fee: '-0.03',
            ),
        ]);

        self::assertCount(1, $fills);
        self::assertSame('order-1', $fills[0]->exchangeOrderId);
        self::assertEqualsWithDelta(1.0, $fills[0]->quantity, 0.000001);
        self::assertEqualsWithDelta(25005.0, $fills[0]->price, 0.000001);
        self::assertEqualsWithDelta(-0.03, $fills[0]->fee ?? 0.0, 0.000001);
    }

    public function testInfersNetModeReduceOnlyPositionSideForCloseFills(): void
    {
        $closeLong = $this->orderRow(
            state: 'filled',
            filled: '0.1',
            updatedAt: '1767225601000',
            fillSz: '0.1',
            fillPx: '25005',
            tradeId: 'close-long-fill',
        );
        $closeLong['clOrdId'] = 'close-long-client';
        $closeLong['ordId'] = 'close-long-order';
        $closeLong['posSide'] = 'net';
        $closeLong['reduceOnly'] = 'true';
        $closeLong['side'] = 'sell';

        $closeShort = $this->orderRow(
            state: 'filled',
            filled: '0.2',
            updatedAt: '1767225602000',
            fillSz: '0.2',
            fillPx: '25010',
            tradeId: 'close-short-fill',
        );
        $closeShort['clOrdId'] = 'close-short-client';
        $closeShort['ordId'] = 'close-short-order';
        $closeShort['posSide'] = 'net';
        $closeShort['reduceOnly'] = 'true';
        $closeShort['side'] = 'buy';

        $fills = $this->normalizer->normalizeFills([$closeLong, $closeShort]);
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$closeLong]);

        self::assertSame(ExchangePositionSide::LONG, $lifecycle->positionSide);
        self::assertSame(ExchangePositionSide::LONG, $fills[0]->positionSide);
        self::assertSame(ExchangePositionSide::SHORT, $fills[1]->positionSide);
    }

    public function testIgnoresNonSwapFills(): void
    {
        $spotFill = $this->orderRow(
            state: 'filled',
            filled: '1',
            updatedAt: '1767225601000',
            fillSz: '1',
            fillPx: '25005',
            tradeId: 'spot-fill',
        );
        $spotFill['instId'] = 'BTC-USDT';
        $spotFill['instType'] = 'SPOT';

        $fills = $this->normalizer->normalizeFills([$spotFill]);

        self::assertSame([], $fills);
    }

    public function testRejectsNonSwapOrderLifecycleRows(): void
    {
        $spotOrder = $this->orderRow(state: 'filled', filled: '1', updatedAt: '1767225601000');
        $spotOrder['instId'] = 'BTC-USDT';
        $spotOrder['instType'] = 'SPOT';

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$spotOrder]);

        self::assertSame(OkxLifecycleStatus::UNKNOWN_REQUIRES_RESYNC, $lifecycle->status);
        self::assertTrue($lifecycle->requiresResync);
        self::assertSame('', $lifecycle->symbol);
        self::assertSame('', $lifecycle->exchangeOrderId);
        self::assertSame(0, $lifecycle->deduplicatedEventCount);
        self::assertContains('non_swap_order_ignored', $lifecycle->qualityFlags);
    }

    public function testPreservesAlgoOrderIdNamespaceInLifecycleAndFills(): void
    {
        $row = $this->orderRow(
            state: 'filled',
            filled: '1',
            updatedAt: '1767225601000',
            fillSz: '1',
            fillPx: '25005',
            tradeId: 'algo-fill',
        );
        unset($row['ordId'], $row['clOrdId']);
        $row['algoId'] = '90001';
        $row['algoClOrdId'] = 'algo-client-1';

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$row]);

        self::assertSame('algo:90001', $lifecycle->exchangeOrderId);
        self::assertSame('algo-client-1', $lifecycle->clientOrderId);
        self::assertCount(1, $lifecycle->fills);
        self::assertSame('algo:90001', $lifecycle->fills[0]->exchangeOrderId);
    }

    public function testPrefersAlgoIdentifiersWhenAlgoRowsAlsoContainOrderIdentifiers(): void
    {
        $row = $this->orderRow(
            state: 'filled',
            filled: '1',
            updatedAt: '1767225601000',
            fillSz: '1',
            fillPx: '25005',
            tradeId: 'algo-child-fill',
        );
        $row['algoId'] = '90009';
        $row['algoClOrdId'] = 'algo-client-blank-clord';
        $row['channel'] = 'orders-algo';
        $row['clOrdId'] = '';
        $row['ordId'] = 'child-order-1';

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$row]);

        self::assertSame('algo:90009', $lifecycle->exchangeOrderId);
        self::assertSame('algo-client-blank-clord', $lifecycle->clientOrderId);
        self::assertCount(1, $lifecycle->fills);
        self::assertSame('algo:90009', $lifecycle->fills[0]->exchangeOrderId);
        self::assertSame('algo-client-blank-clord', $lifecycle->fills[0]->clientOrderId);
    }

    public function testPrefersChildOrderIdentifiersForNormalRowsWithParentAlgoFields(): void
    {
        $row = $this->orderRow(
            state: 'filled',
            filled: '1',
            updatedAt: '1767225601000',
            fillSz: '1',
            fillPx: '25005',
            tradeId: 'child-fill',
        );
        $row['algoId'] = '90010';
        $row['algoClOrdId'] = 'parent-algo-client';
        $row['channel'] = 'orders';
        $row['clOrdId'] = 'child-client-1';
        $row['ordId'] = 'child-order-1';

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$row]);

        self::assertSame('child-order-1', $lifecycle->exchangeOrderId);
        self::assertSame('child-client-1', $lifecycle->clientOrderId);
        self::assertCount(1, $lifecycle->fills);
        self::assertSame('child-order-1', $lifecycle->fills[0]->exchangeOrderId);
        self::assertSame('child-client-1', $lifecycle->fills[0]->clientOrderId);
    }

    public function testNormalizesOptimalLimitIocAsMarketOrder(): void
    {
        $row = $this->orderRow(state: 'live', filled: '0', updatedAt: '1767225601000');
        $row['ordType'] = 'optimal_limit_ioc';

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$row]);

        self::assertSame(ExchangeOrderType::MARKET, $lifecycle->orderType);
    }

    public function testPreservesTakeProfitAlgoLimitPrice(): void
    {
        $row = $this->orderRow(state: 'live', filled: '0', updatedAt: '1767225601000');
        unset($row['ordId'], $row['clOrdId'], $row['px']);
        $row['algoId'] = '90002';
        $row['algoClOrdId'] = 'tp-client-1';
        $row['ordType'] = 'conditional';
        $row['tpOrdPx'] = '25550';
        $row['tpTriggerPx'] = '25500';

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$row]);

        self::assertSame(ExchangeOrderType::TAKE_PROFIT, $lifecycle->orderType);
        self::assertEqualsWithDelta(25550.0, $lifecycle->price ?? 0.0, 0.000001);
    }

    public function testPreservesStopLossAlgoLimitPrice(): void
    {
        $row = $this->orderRow(state: 'live', filled: '0', updatedAt: '1767225601000');
        unset($row['ordId'], $row['clOrdId'], $row['px']);
        $row['algoId'] = '90003';
        $row['algoClOrdId'] = 'sl-client-1';
        $row['ordType'] = 'conditional';
        $row['slOrdPx'] = '24825';
        $row['slTriggerPx'] = '24800';

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$row]);

        self::assertSame(ExchangeOrderType::STOP_LOSS, $lifecycle->orderType);
        self::assertEqualsWithDelta(24825.0, $lifecycle->price ?? 0.0, 0.000001);
    }

    public function testDoesNotEmitMarketSentinelAsLifecyclePrice(): void
    {
        $row = $this->orderRow(state: 'live', filled: '0', updatedAt: '1767225601000');
        unset($row['px']);
        $row['ordType'] = 'trigger';
        $row['ordPx'] = '-1';

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$row]);

        self::assertSame(ExchangeOrderType::TRIGGER, $lifecycle->orderType);
        self::assertNull($lifecycle->price);
    }

    public function testCancelFillRaceKeepsKnownFillAndCanceledStatus(): void
    {
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $this->orderRow(state: 'canceled', filled: '0.4', updatedAt: '1767225603000'),
            $this->orderRow(
                state: 'partially_filled',
                filled: '0.4',
                fillSz: '0.4',
                fillPx: '25005',
                tradeId: 'trade-race',
                updatedAt: '1767225601000',
            ),
        ]);

        self::assertSame(OkxLifecycleStatus::CANCELED, $lifecycle->status);
        self::assertCount(1, $lifecycle->fills);
        self::assertContains('terminal_cancel_with_fill', $lifecycle->qualityFlags);
    }

    public function testDuplicateEventsAreDeduplicated(): void
    {
        $row = $this->orderRow(
            state: 'partially_filled',
            filled: '0.4',
            fillSz: '0.4',
            fillPx: '25005',
            tradeId: 'dup-fill',
            updatedAt: '1767225601000',
        );

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$row, $row]);

        self::assertSame(1, $lifecycle->deduplicatedEventCount);
        self::assertCount(1, $lifecycle->fills);
    }

    public function testOutOfOrderEventsUseNewestState(): void
    {
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $this->orderRow(state: 'filled', filled: '1', updatedAt: '1767225603000'),
            $this->orderRow(state: 'live', filled: '0', updatedAt: '1767225600000'),
        ]);

        self::assertSame(OkxLifecycleStatus::FILLED, $lifecycle->status);
        self::assertEqualsWithDelta(1.0, $lifecycle->filledQuantity, 0.000001);
    }

    public function testEqualTimestampTerminalStateWinsOverOpenState(): void
    {
        $timestamp = '1767225603000';

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $this->orderRow(state: 'filled', filled: '1', updatedAt: $timestamp),
            $this->orderRow(state: 'live', filled: '0', updatedAt: $timestamp),
        ]);

        self::assertSame(OkxLifecycleStatus::FILLED, $lifecycle->status);
        self::assertEqualsWithDelta(1.0, $lifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.0, $lifecycle->remainingQuantity, 0.000001);
    }

    public function testUnknownOrderRequiresResync(): void
    {
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $this->orderRow(state: 'mystery_state', filled: '0', updatedAt: '1767225600000'),
        ]);

        self::assertSame(OkxLifecycleStatus::UNKNOWN_REQUIRES_RESYNC, $lifecycle->status);
        self::assertTrue($lifecycle->requiresResync);
        self::assertContains('unknown_order_state', $lifecycle->qualityFlags);
    }

    public function testNormalizesPositionSnapshot(): void
    {
        $positions = $this->normalizer->normalizePositions([[
            'instId' => 'BTC-USDT-SWAP',
            'instType' => 'SWAP',
            'posSide' => 'long',
            'pos' => '0.5',
            'avgPx' => '25000',
            'markPx' => '25100',
            'upl' => '50',
            'lever' => '3',
            'uTime' => '1767225600000',
        ]]);

        self::assertCount(1, $positions);
        self::assertSame('BTCUSDT', $positions[0]->symbol);
        self::assertSame(ExchangePositionSide::LONG, $positions[0]->side);
        self::assertEqualsWithDelta(0.5, $positions[0]->size, 0.000001);
        self::assertEqualsWithDelta(25000.0, $positions[0]->entryPrice, 0.000001);
    }

    public function testNormalizesNetModeZeroPositionAsBothSideClosures(): void
    {
        $positions = $this->normalizer->normalizePositions([[
            'instId' => 'BTC-USDT-SWAP',
            'instType' => 'SWAP',
            'posSide' => 'net',
            'pos' => '0',
            'avgPx' => '0',
            'markPx' => '25100',
            'upl' => '0',
            'lever' => '3',
            'uTime' => '1767225600000',
        ]]);

        self::assertCount(2, $positions);
        self::assertSame(ExchangePositionSide::LONG, $positions[0]->side);
        self::assertSame(ExchangePositionSide::SHORT, $positions[1]->side);
        self::assertEqualsWithDelta(0.0, $positions[0]->size, 0.000001);
        self::assertEqualsWithDelta(0.0, $positions[1]->size, 0.000001);
    }

    public function testNormalizesErrorToStableFailedStatus(): void
    {
        $error = $this->normalizer->normalizeError([
            'code' => '51008',
            'msg' => 'Order failed',
            'data' => [['sCode' => '51008', 'sMsg' => 'insufficient balance', 'ordId' => 'order-1']],
        ]);

        self::assertSame(OkxLifecycleStatus::FAILED, $error->status);
        self::assertSame('51008', $error->code);
        self::assertSame('order-1', $error->exchangeOrderId);
        self::assertArrayNotHasKey('apiKey', $error->redactedPayload);
    }

    public function testRedactsCredentialKeyVariantsRecursively(): void
    {
        $row = $this->orderRow(state: 'live', filled: '0', updatedAt: '1767225601000');
        $row['OK-ACCESS-KEY'] = 'key-secret';
        $row['OK-ACCESS-SIGN'] = 'signature-secret';
        $row['api_key'] = 'api-key-secret';
        $row['Authorization'] = 'Bearer raw-token';
        $row['nested'] = ['token' => 'nested-token', 'safe_value' => 'visible'];

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$row]);

        self::assertSame('[REDACTED]', $lifecycle->redactedPayload['OK-ACCESS-KEY']);
        self::assertSame('[REDACTED]', $lifecycle->redactedPayload['OK-ACCESS-SIGN']);
        self::assertSame('[REDACTED]', $lifecycle->redactedPayload['api_key']);
        self::assertSame('[REDACTED]', $lifecycle->redactedPayload['Authorization']);
        self::assertSame('[REDACTED]', $lifecycle->redactedPayload['nested']['token']);
        self::assertSame('visible', $lifecycle->redactedPayload['nested']['safe_value']);
    }

    /**
     * @return array<string,mixed>
     */
    private function orderRow(
        string $state,
        string $filled,
        string $updatedAt,
        string $avgPx = '',
        string $fillSz = '',
        string $fillPx = '',
        string $tradeId = '',
        string $fee = '',
    ): array {
        return [
            'accFillSz' => $filled,
            'avgPx' => $avgPx,
            'cTime' => '1767225599000',
            'clOrdId' => 'client-1',
            'fee' => $fee,
            'feeCcy' => $fee !== '' ? 'USDT' : '',
            'fillFee' => $fee,
            'fillFeeCcy' => $fee !== '' ? 'USDT' : '',
            'fillPx' => $fillPx,
            'fillSz' => $fillSz,
            'fillTime' => $updatedAt,
            'instId' => 'BTC-USDT-SWAP',
            'instType' => 'SWAP',
            'ordId' => 'order-1',
            'ordType' => 'limit',
            'posSide' => 'long',
            'px' => '25000',
            'reduceOnly' => 'false',
            'side' => 'buy',
            'state' => $state,
            'sz' => '1',
            'tradeId' => $tradeId,
            'uTime' => $updatedAt,
        ];
    }
}
