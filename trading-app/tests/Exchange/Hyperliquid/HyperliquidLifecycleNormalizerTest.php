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
            $this->orderRow(remaining: '0.6', original: '1', status: 'open', updatedAt: 1_767_225_600_000),
            $this->fillRow(quantity: '0.4', price: '25010', hash: 'fill-1', time: 1_767_225_601_000),
        ]);

        self::assertSame(HyperliquidLifecycleStatus::PARTIALLY_FILLED, $lifecycle->status);
        self::assertSame('BTCUSDT', $lifecycle->symbol);
        self::assertSame('1001', $lifecycle->exchangeOrderId);
        self::assertEqualsWithDelta(1.0, $lifecycle->quantity, 0.000001);
        self::assertEqualsWithDelta(0.4, $lifecycle->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.6, $lifecycle->remainingQuantity, 0.000001);
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

        self::assertSame(HyperliquidLifecycleStatus::FAILED, $insufficient->status);
        self::assertSame('insufficient_collateral', $insufficient->code);
        self::assertSame('1001', $insufficient->exchangeOrderId);
        self::assertSame('[redacted]', $insufficient->redactedPayload['response']['signature']);
        self::assertSame('market_unavailable', $marketUnavailable->code);
        self::assertSame('cid-market', $marketUnavailable->clientOrderId);
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
