<?php

declare(strict_types=1);

namespace App\Tests\Application\Runner;

use App\Application\Runner\OpenStateSnapshotSerializer;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderStatus;
use App\Common\Enum\OrderType;
use App\Common\Enum\PositionSide;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\PositionDto;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenStateSnapshotSerializer::class)]
final class OpenStateSnapshotSerializerTest extends TestCase
{
    public function testSerializesProviderDtosIntoJsonSafeArrays(): void
    {
        $serializer = new OpenStateSnapshotSerializer();

        $position = new PositionDto(
            symbol: 'BTCUSDT',
            side: PositionSide::LONG,
            size: BigDecimal::of('1.5'),
            entryPrice: BigDecimal::of('60000'),
            markPrice: BigDecimal::of('61000'),
            unrealizedPnl: BigDecimal::of('1500'),
            realizedPnl: BigDecimal::of('0'),
            margin: BigDecimal::of('900'),
            leverage: BigDecimal::of('20'),
            openedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $order = new OrderDto(
            orderId: 'ORD-1',
            symbol: 'ETHUSDT',
            side: OrderSide::BUY,
            type: OrderType::LIMIT,
            status: OrderStatus::PENDING,
            quantity: BigDecimal::of('10'),
            price: BigDecimal::of('3000'),
            stopPrice: null,
            filledQuantity: BigDecimal::of('0'),
            remainingQuantity: BigDecimal::of('10'),
            averagePrice: null,
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $snapshot = $serializer->serialize([$position], [$order]);

        self::assertSame(['open_positions', 'open_orders'], array_keys($snapshot));
        self::assertSame('BTCUSDT', $snapshot['open_positions'][0]['symbol']);
        self::assertSame('long', $snapshot['open_positions'][0]['side']);
        self::assertSame('ETHUSDT', $snapshot['open_orders'][0]['symbol']);
        self::assertSame('ORD-1', $snapshot['open_orders'][0]['order_id']);
        self::assertNull($snapshot['open_orders'][0]['stop_price']);

        // Doit être strictement encodable en JSON (aucun objet résiduel).
        self::assertJson(json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    public function testReturnsEmptyShapeWhenNothingOpen(): void
    {
        $serializer = new OpenStateSnapshotSerializer();

        self::assertSame(
            ['open_positions' => [], 'open_orders' => []],
            $serializer->serialize([], []),
        );
    }

    public function testPassesThroughPreNormalizedArrays(): void
    {
        $serializer = new OpenStateSnapshotSerializer();

        $snapshot = $serializer->serialize(
            [['symbol' => 'ADAUSDT', 'side' => 'short']],
            [['symbol' => 'ADAUSDT', 'order_id' => 'X']],
        );

        self::assertSame('ADAUSDT', $snapshot['open_positions'][0]['symbol']);
        self::assertSame('X', $snapshot['open_orders'][0]['order_id']);
    }
}
