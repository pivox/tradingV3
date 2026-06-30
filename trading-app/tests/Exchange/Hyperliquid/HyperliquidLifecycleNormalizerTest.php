<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Hyperliquid\HyperliquidActionFactory;
use App\Exchange\Hyperliquid\Lifecycle\HyperliquidLifecycleNormalizer;
use App\Exchange\Hyperliquid\Lifecycle\HyperliquidLifecycleStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidLifecycleNormalizer::class)]
#[CoversClass(HyperliquidLifecycleStatus::class)]
#[CoversClass(HyperliquidActionFactory::class)]
final class HyperliquidLifecycleNormalizerTest extends TestCase
{
    private HyperliquidLifecycleNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new HyperliquidLifecycleNormalizer(new HyperliquidActionFactory());
    }

    public function testNormalizesOrderRequestWithoutBroadcast(): void
    {
        $action = $this->normalizer->normalizeOrderRequest(0, new PlaceOrderRequest(
            exchange: Exchange::HYPERLIQUID,
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
            postOnly: true,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'cid-hl-normalized',
        ));

        self::assertSame('order', $action['type']);
        self::assertSame(0, $action['orders'][0]['a']);
        self::assertSame('25000', $action['orders'][0]['p']);
        self::assertSame('Alo', $action['orders'][0]['t']['limit']['tif']);
        self::assertArrayNotHasKey('secret', $action);
    }

    public function testNormalizesPartialFillLifecycleWithFee(): void
    {
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $this->orderRow(remaining: '0.6', original: '1', status: 'open', updatedAt: 1_767_225_602_000),
            $this->fillRow(quantity: '0.4', price: '25010', hash: 'fill-1', time: 1_767_225_601_000),
        ]);

        self::assertSame(HyperliquidLifecycleStatus::PARTIALLY_FILLED, $lifecycle->status);
        self::assertSame('BTCUSDT', $lifecycle->symbol);
        self::assertSame('1001', $lifecycle->exchangeOrderId);
        self::assertEqualsWithDelta(1.0, $lifecycle->quantity, 0.000001);
        self::assertEqualsWithDelta(0.4, $lifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.6, $lifecycle->remainingQuantity, 0.000001);
        self::assertSame(1_767_225_602, $lifecycle->updatedAt->getTimestamp());
        self::assertCount(1, $lifecycle->fills);
        self::assertSame('fill-1', $lifecycle->fills[0]->fillId);
        self::assertSame('USDC', $lifecycle->fills[0]->feeCurrency);
        self::assertEqualsWithDelta(0.12, $lifecycle->fills[0]->fee ?? 0.0, 0.000001);
    }

    public function testDeduplicatesAndUsesNewestOutOfOrderStatus(): void
    {
        $open = $this->orderRow(remaining: '1', original: '1', status: 'open', updatedAt: 1_767_225_600_000);
        $filled = $this->orderRow(remaining: '0', original: '1', status: 'filled', updatedAt: 1_767_225_603_000);
        $filled['avgPx'] = '25005';

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$filled, $open, $filled]);

        self::assertSame(HyperliquidLifecycleStatus::FILLED, $lifecycle->status);
        self::assertEqualsWithDelta(1.0, $lifecycle->filledQuantity, 0.000001);
        self::assertSame(2, $lifecycle->deduplicatedEventCount);
        self::assertContains('duplicate_event', $lifecycle->qualityFlags);
    }

    public function testRecomputesRemainingWhenNewerFillFollowsOrderSnapshot(): void
    {
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $this->orderRow(remaining: '1', original: '1', status: 'open', updatedAt: 1_767_225_600_000),
            $this->fillRow(quantity: '0.4', price: '25010', hash: 'newer-fill', time: 1_767_225_601_000),
        ]);

        self::assertSame(HyperliquidLifecycleStatus::PARTIALLY_FILLED, $lifecycle->status);
        self::assertEqualsWithDelta(0.4, $lifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.6, $lifecycle->remainingQuantity, 0.000001);
    }

    public function testRecomputesRemainingWhenSameMillisecondFillFollowsOrderSnapshot(): void
    {
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $this->orderRow(remaining: '1', original: '1', status: 'open', updatedAt: 1_767_225_600_000),
            $this->fillRow(quantity: '0.4', price: '25010', hash: 'same-ms-fill', time: 1_767_225_600_000),
        ]);

        self::assertSame(HyperliquidLifecycleStatus::PARTIALLY_FILLED, $lifecycle->status);
        self::assertEqualsWithDelta(0.4, $lifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.6, $lifecycle->remainingQuantity, 0.000001);
    }

    public function testDoesNotDoubleCountFillsAlreadyReflectedByCurrentSnapshot(): void
    {
        $snapshot = $this->orderRow(remaining: '0.6', original: '1', status: 'open', updatedAt: 1_767_225_600_000);
        unset($snapshot['uTime']);

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $snapshot,
            $this->fillRow(quantity: '0.4', price: '25010', hash: 'snapshot-fill', time: 1_767_225_601_000),
        ]);

        self::assertSame(HyperliquidLifecycleStatus::PARTIALLY_FILLED, $lifecycle->status);
        self::assertEqualsWithDelta(0.4, $lifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.6, $lifecycle->remainingQuantity, 0.000001);
    }

    public function testCountsFillThatCompletesPartialOrderSnapshot(): void
    {
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $this->orderRow(remaining: '0.1', original: '1', status: 'open', updatedAt: 1_767_225_600_000),
            $this->fillRow(quantity: '0.1', price: '25010', hash: 'remaining-fill', time: 1_767_225_601_000),
        ]);

        self::assertSame(HyperliquidLifecycleStatus::FILLED, $lifecycle->status);
        self::assertEqualsWithDelta(1.0, $lifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.0, $lifecycle->remainingQuantity, 0.000001);
    }

    public function testIgnoresSpotCoinFillsInPerpetualLifecycle(): void
    {
        $spotFill = $this->fillRow(quantity: '1', price: '10', hash: 'spot-fill', time: 1_767_225_601_000);
        $spotFill['coin'] = '@107';
        $slashSpotFill = $this->fillRow(quantity: '1', price: '10', hash: 'slash-spot-fill', time: 1_767_225_602_000);
        $slashSpotFill['coin'] = 'PURR/USDC';

        $fills = $this->normalizer->normalizeFills([$spotFill, $slashSpotFill]);
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $this->orderRow(remaining: '1', original: '1', status: 'open', updatedAt: 1_767_225_600_000),
            $spotFill,
            $slashSpotFill,
        ]);

        self::assertSame([], $fills);
        self::assertSame(HyperliquidLifecycleStatus::OPEN, $lifecycle->status);
        self::assertSame('BTCUSDT', $lifecycle->symbol);
        self::assertSame([], $lifecycle->fills);
        self::assertEqualsWithDelta(0.0, $lifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(1.0, $lifecycle->remainingQuantity, 0.000001);
        self::assertContains('unsupported_lifecycle_row_ignored', $lifecycle->qualityFlags);
    }

    public function testPreservesMillisecondPrecisionOnFillTimestamps(): void
    {
        $fills = $this->normalizer->normalizeFills([
            $this->fillRow(quantity: '0.1', price: '25010', hash: 'fill-ms-1', time: 1_767_225_601_123),
            $this->fillRow(quantity: '0.1', price: '25011', hash: 'fill-ms-2', time: 1_767_225_601_456),
        ]);

        self::assertCount(2, $fills);
        self::assertSame('1767225601.123000', $fills[0]->occurredAt->format('U.u'));
        self::assertSame('1767225601.456000', $fills[1]->occurredAt->format('U.u'));
    }

    public function testUnwrapsTwapSliceFillRowsBeforeDeduplication(): void
    {
        $first = ['twapId' => 'twap-a', 'fill' => $this->fillRow(quantity: '0.1', price: '25010', hash: 'twap-fill-1', time: 1_767_225_601_000)];
        $second = ['twapId' => 'twap-b', 'fill' => $this->fillRow(quantity: '0.3', price: '25020', hash: 'twap-fill-2', time: 1_767_225_602_000)];

        $fills = $this->normalizer->normalizeFills([$first, $second]);
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $this->orderRow(remaining: '1', original: '1', status: 'open', updatedAt: 1_767_225_600_000),
            $first,
            $second,
        ]);

        self::assertCount(2, $fills);
        self::assertSame('twap-fill-1', $fills[0]->fillId);
        self::assertSame('twap-fill-2', $fills[1]->fillId);
        self::assertEqualsWithDelta(0.4, $lifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.6, $lifecycle->remainingQuantity, 0.000001);
        self::assertCount(2, $lifecycle->fills);
    }

    public function testTreatsOpenOrderSnapshotWithoutStatusAsOpen(): void
    {
        $row = $this->orderRow(remaining: '1', original: '1', status: 'open', updatedAt: 1_767_225_600_000);
        unset($row['status']);

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$row]);

        self::assertSame(HyperliquidLifecycleStatus::OPEN, $lifecycle->status);
        self::assertFalse($lifecycle->requiresResync);
    }

    public function testKeepsDistinctFillsSharingTransactionHash(): void
    {
        $first = $this->fillRow(quantity: '0.2', price: '25000', hash: 'shared-tx-hash', time: 1_767_225_601_000);
        $first['tid'] = 10_001;
        $second = $this->fillRow(quantity: '0.2', price: '25000', hash: 'shared-tx-hash', time: 1_767_225_601_000);
        $second['tid'] = 10_002;

        $fills = $this->normalizer->normalizeFills([$first, $second]);

        self::assertCount(2, $fills);
        self::assertSame('10001', $fills[0]->fillId);
        self::assertSame('10002', $fills[1]->fillId);
    }

    public function testUsesFillDirectionForClosePositionSide(): void
    {
        $closeLong = $this->fillRow(quantity: '0.2', price: '25000', hash: 'close-long', time: 1_767_225_601_000);
        $closeLong['side'] = 'A';
        $closeLong['dir'] = 'Close Long';
        $closeShort = $this->fillRow(quantity: '0.2', price: '25000', hash: 'close-short', time: 1_767_225_602_000);
        $closeShort['side'] = 'B';
        $closeShort['dir'] = 'Close Short';

        $fills = $this->normalizer->normalizeFills([$closeLong, $closeShort]);

        self::assertSame(ExchangePositionSide::LONG, $fills[0]->positionSide);
        self::assertSame(ExchangePositionSide::SHORT, $fills[1]->positionSide);
    }

    public function testOrderAbsentButFillPresentRequiresResyncAndKeepsFill(): void
    {
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $this->fillRow(quantity: '0.25', price: '25001', hash: 'standalone-fill', time: 1_767_225_604_000),
        ]);

        self::assertSame(HyperliquidLifecycleStatus::UNKNOWN_REQUIRES_RESYNC, $lifecycle->status);
        self::assertTrue($lifecycle->requiresResync);
        self::assertContains('order_absent_fill_present', $lifecycle->qualityFlags);
        self::assertCount(1, $lifecycle->fills);
    }

    public function testNormalizesNestedOrderStatusWrapper(): void
    {
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([[
            'status' => 'order',
            'order' => [
                'status' => 'open',
                'statusTimestamp' => 1_767_225_606_000,
                'order' => [
                    'coin' => 'BTC',
                    'oid' => 1003,
                    'cloid' => 'client-nested',
                    'side' => 'B',
                    'sz' => '0.7',
                    'origSz' => '1',
                    'limitPx' => '25020',
                    'orderType' => 'Limit',
                    'timestamp' => 1_767_225_600_000,
                ],
            ],
        ]]);

        self::assertSame(HyperliquidLifecycleStatus::PARTIALLY_FILLED, $lifecycle->status);
        self::assertSame('BTCUSDT', $lifecycle->symbol);
        self::assertSame('1003', $lifecycle->exchangeOrderId);
        self::assertSame('client-nested', $lifecycle->clientOrderId);
        self::assertEqualsWithDelta(1.0, $lifecycle->quantity, 0.000001);
        self::assertEqualsWithDelta(0.7, $lifecycle->remainingQuantity, 0.000001);
    }

    public function testPreservesTopLevelHistoricalOrderStatusTimestamp(): void
    {
        $lifecycle = $this->normalizer->normalizeOrderLifecycle([[
            'status' => 'filled',
            'statusTimestamp' => 1_767_225_700_000,
            'order' => [
                'coin' => 'BTC',
                'oid' => 1004,
                'side' => 'B',
                'sz' => '0',
                'origSz' => '1',
                'limitPx' => '25020',
                'orderType' => 'Limit',
                'timestamp' => 1_767_225_600_000,
            ],
        ]]);

        self::assertSame(HyperliquidLifecycleStatus::FILLED, $lifecycle->status);
        self::assertSame(1_767_225_700, $lifecycle->updatedAt->getTimestamp());
    }

    public function testNormalizesCamelCaseTerminalOrderStatuses(): void
    {
        $canceled = $this->orderRow(remaining: '1', original: '1', status: 'marginCanceled', updatedAt: 1_767_225_606_000);
        $rejected = $this->orderRow(remaining: '1', original: '1', status: 'badAloPxRejected', updatedAt: 1_767_225_607_000);
        $scheduled = $this->orderRow(remaining: '1', original: '1', status: 'scheduledCancel', updatedAt: 1_767_225_608_000);
        $iocRejected = $this->orderRow(remaining: '1', original: '1', status: 'iocCancelRejected', updatedAt: 1_767_225_609_000);

        self::assertSame(HyperliquidLifecycleStatus::CANCELED, $this->normalizer->normalizeOrderLifecycle([$canceled])->status);
        self::assertSame(HyperliquidLifecycleStatus::REJECTED, $this->normalizer->normalizeOrderLifecycle([$rejected])->status);
        self::assertSame(HyperliquidLifecycleStatus::CANCELED, $this->normalizer->normalizeOrderLifecycle([$scheduled])->status);
        self::assertSame(HyperliquidLifecycleStatus::REJECTED, $this->normalizer->normalizeOrderLifecycle([$iocRejected])->status);
    }

    public function testDoesNotTreatTerminalZeroSizeCancelOrRejectAsFill(): void
    {
        $canceled = $this->orderRow(remaining: '0', original: '1', status: 'marginCanceled', updatedAt: 1_767_225_610_000);
        $rejected = $this->orderRow(remaining: '0', original: '1', status: 'badAloPxRejected', updatedAt: 1_767_225_611_000);

        $canceledLifecycle = $this->normalizer->normalizeOrderLifecycle([$canceled]);
        $rejectedLifecycle = $this->normalizer->normalizeOrderLifecycle([$rejected]);

        self::assertSame(HyperliquidLifecycleStatus::CANCELED, $canceledLifecycle->status);
        self::assertEqualsWithDelta(0.0, $canceledLifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.0, $canceledLifecycle->remainingQuantity, 0.000001);
        self::assertSame(HyperliquidLifecycleStatus::REJECTED, $rejectedLifecycle->status);
        self::assertEqualsWithDelta(0.0, $rejectedLifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.0, $rejectedLifecycle->remainingQuantity, 0.000001);
    }

    public function testPreservesSnapshotPartialFillOnTerminalCancel(): void
    {
        $canceled = $this->orderRow(remaining: '0.6', original: '1', status: 'marginCanceled', updatedAt: 1_767_225_610_000);

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([$canceled]);

        self::assertSame(HyperliquidLifecycleStatus::CANCELED, $lifecycle->status);
        self::assertEqualsWithDelta(0.4, $lifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.6, $lifecycle->remainingQuantity, 0.000001);
    }

    public function testDoesNotDowngradeTerminalSnapshotPartialFillWithIncompleteFillPage(): void
    {
        $canceled = $this->orderRow(remaining: '0.6', original: '1', status: 'marginCanceled', updatedAt: 1_767_225_610_000);

        $lifecycle = $this->normalizer->normalizeOrderLifecycle([
            $canceled,
            $this->fillRow(quantity: '0.1', price: '25010', hash: 'incomplete-fill-page', time: 1_767_225_601_000),
        ]);

        self::assertSame(HyperliquidLifecycleStatus::CANCELED, $lifecycle->status);
        self::assertEqualsWithDelta(0.4, $lifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.6, $lifecycle->remainingQuantity, 0.000001);
    }

    public function testNormalizesStopOrdersAsTriggerProtectionTypes(): void
    {
        $stop = $this->orderRow(remaining: '1', original: '1', status: 'open', updatedAt: 1_767_225_609_000);
        $stop['orderType'] = 'Stop Market';
        $stop['triggerPx'] = '24900';
        $takeProfit = $this->orderRow(remaining: '1', original: '1', status: 'open', updatedAt: 1_767_225_610_000);
        $takeProfit['orderType'] = 'Take Profit Limit';
        $takeProfit['triggerPx'] = '26000';

        self::assertSame(ExchangeOrderType::STOP_LOSS, $this->normalizer->normalizeOrderLifecycle([$stop])->orderType);
        self::assertSame(ExchangeOrderType::TAKE_PROFIT, $this->normalizer->normalizeOrderLifecycle([$takeProfit])->orderType);
    }

    public function testIgnoresZeroTriggerPriceOnStandardOrders(): void
    {
        $limit = $this->orderRow(remaining: '1', original: '1', status: 'open', updatedAt: 1_767_225_611_000);
        $limit['orderType'] = 'Limit';
        $limit['triggerPx'] = '0.0';
        $limit['isTrigger'] = false;

        self::assertSame(ExchangeOrderType::LIMIT, $this->normalizer->normalizeOrderLifecycle([$limit])->orderType);
    }

    public function testNormalizesPositionSnapshotAndZeroClose(): void
    {
        $positions = $this->normalizer->normalizePositions([
            ['position' => [
                'coin' => 'BTC',
                'szi' => '0.5',
                'entryPx' => '25000',
                'markPx' => '25100',
                'unrealizedPnl' => '50',
                'marginUsed' => '100',
                'leverage' => ['value' => '3'],
                'time' => 1_767_225_604_000,
            ]],
            ['position' => ['coin' => 'ETH', 'szi' => '0', 'time' => 1_767_225_605_000]],
        ]);

        self::assertCount(2, $positions);
        self::assertSame(ExchangePositionSide::LONG, $positions[0]->side);
        self::assertEqualsWithDelta(0.5, $positions[0]->size, 0.000001);
        self::assertSame(ExchangePositionSide::LONG, $positions[1]->side);
        self::assertEqualsWithDelta(0.0, $positions[1]->size, 0.000001);
        self::assertContains('position_closed_zero_size', $positions[1]->qualityFlags);
    }

    public function testNormalizesFundingRow(): void
    {
        $funding = $this->normalizer->normalizeFunding([[
            'time' => 1_767_225_606_000,
            'delta' => ['coin' => 'BTC', 'usdc' => '-0.42', 'fundingRate' => '0.0001'],
        ]]);

        self::assertCount(1, $funding);
        self::assertSame('BTCUSDT', $funding[0]->symbol);
        self::assertEqualsWithDelta(-0.42, $funding[0]->amount, 0.000001);
        self::assertSame('USDC', $funding[0]->currency);
        self::assertSame('funding', $funding[0]->role);
    }

    public function testNormalizesInsufficientCollateralAndMarketUnavailableErrors(): void
    {
        $insufficient = $this->normalizer->normalizeError([
            'status' => 'err',
            'response' => [
                'error' => 'Insufficient margin to place order',
                'oid' => 1001,
                'signature' => 'must-not-leak',
            ],
        ]);
        $marketUnavailable = $this->normalizer->normalizeError([
            'error' => 'Market is not open for this asset',
            'cloid' => 'cid-market',
        ]);
        $batched = $this->normalizer->normalizeError([
            'response' => [
                'type' => 'order',
                'data' => [
                    'statuses' => [[
                        'error' => 'Insufficient collateral available',
                        'oid' => 1002,
                        'cloid' => 'cid-batched',
                    ]],
                ],
            ],
        ]);

        self::assertSame(HyperliquidLifecycleStatus::FAILED, $insufficient->status);
        self::assertSame('insufficient_collateral', $insufficient->code);
        self::assertSame('1001', $insufficient->exchangeOrderId);
        self::assertSame('[redacted]', $insufficient->redactedPayload['response']['signature']);
        self::assertSame('market_unavailable', $marketUnavailable->code);
        self::assertSame('cid-market', $marketUnavailable->clientOrderId);
        self::assertSame('insufficient_collateral', $batched->code);
        self::assertSame('1002', $batched->exchangeOrderId);
        self::assertSame('cid-batched', $batched->clientOrderId);
    }

    /**
     * @return array<string,mixed>
     */
    private function orderRow(string $remaining, string $original, string $status, int $updatedAt): array
    {
        return [
            'coin' => 'BTC',
            'oid' => 1001,
            'cloid' => 'client-a',
            'side' => 'B',
            'sz' => $remaining,
            'origSz' => $original,
            'limitPx' => '25000',
            'orderType' => 'Limit',
            'status' => $status,
            'timestamp' => 1_767_225_599_000,
            'uTime' => $updatedAt,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fillRow(string $quantity, string $price, string $hash, int $time): array
    {
        return [
            'coin' => 'BTC',
            'oid' => 1001,
            'cloid' => 'client-a',
            'side' => 'B',
            'sz' => $quantity,
            'px' => $price,
            'fee' => '0.12',
            'hash' => $hash,
            'time' => $time,
        ];
    }
}
