<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Adapter;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Entity\OrderIntent;
use App\Exchange\Adapter\BitmartLegacyOrderMapper;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Types\Side;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BitmartLegacyOrderMapper::class)]
final class BitmartLegacyOrderMapperTest extends TestCase
{
    public function testBuildsLegacyLimitPayloadAndOptionsForLongEntry(): void
    {
        $mapper = new BitmartLegacyOrderMapper();
        $payload = $mapper->entrySubmitPayload($this->plan(side: Side::Long), 'cid-1');

        self::assertSame(1, $payload['side']);
        self::assertSame('limit', $payload['type']);
        self::assertSame(4, $payload['mode']);
        self::assertSame('isolated', $payload['open_type']);
        self::assertSame('26000', $payload['preset_take_profit_price']);
        self::assertSame('24000', $payload['preset_stop_loss_price']);
        self::assertSame(OrderSide::BUY, $mapper->providerSide($payload));
        self::assertSame(OrderType::LIMIT, $mapper->providerOrderType($this->plan(side: Side::Long)));

        $options = $mapper->orderOptions($payload + [
            'decision_key' => 'dk-1',
            'order_intent_id' => 42,
        ]);

        self::assertSame(1, $options['side']);
        self::assertSame(4, $options['mode']);
        self::assertSame('isolated', $options['open_type']);
        self::assertSame('cid-1', $options['client_order_id']);
        self::assertSame('dk-1', $options['decision_key']);
        self::assertSame(42, $options['order_intent_id']);
    }

    public function testBuildsMarketPayloadWithoutAttachedProtectionOptions(): void
    {
        $mapper = new BitmartLegacyOrderMapper();
        $payload = $mapper->entrySubmitPayload($this->plan(side: Side::Short, orderType: 'market', orderMode: 1), 'cid-2');

        self::assertSame(4, $payload['side']);
        self::assertSame('market', $payload['type']);
        self::assertSame(3, $payload['mode']);
        self::assertArrayNotHasKey('price', $payload);
        self::assertArrayNotHasKey('preset_stop_loss_price', $payload);
        self::assertArrayNotHasKey('preset_take_profit_price', $payload);
        self::assertSame(OrderSide::SELL, $mapper->providerSide($payload));
        self::assertSame(OrderType::MARKET, $mapper->providerOrderType($this->plan(side: Side::Short, orderType: 'market')));

        $options = $mapper->withoutAttachedProtectionOptions($mapper->orderOptions($payload + [
            'preset_stop_loss_price' => '24000',
            'preset_take_profit_price' => '26000',
        ]));

        self::assertSame(3, $options['mode']);
        self::assertArrayNotHasKey('preset_stop_loss_price', $options);
        self::assertArrayNotHasKey('preset_take_profit_price', $options);
    }

    public function testMapsLegacyCloseSidesToProviderSides(): void
    {
        $mapper = new BitmartLegacyOrderMapper();

        self::assertSame(OrderSide::BUY, $mapper->providerSide(['side' => 2]));
        self::assertSame(OrderSide::SELL, $mapper->providerSide(['side' => 3]));
    }

    public function testBuildsLegacyOrderIntentExecutionParams(): void
    {
        $mapper = new BitmartLegacyOrderMapper();
        $params = $mapper->orderIntentExecutionParams($this->plan(side: Side::Short), 'cid-3');

        self::assertSame(4, $params['side']);
        self::assertSame('limit', $params['type']);
        self::assertSame('isolated', $params['open_type']);
        self::assertSame(OrderIntent::POSITION_MODE_HEDGE, $params['position_mode']);
        self::assertSame(OrderIntent::PRESET_MODE_PRESET_ON_ENTRY, $params['preset_mode']);
        self::assertSame('24000', $params['preset_stop_loss_price']);
        self::assertSame('26000', $params['preset_take_profit_price']);
    }

    private function plan(Side $side, string $orderType = 'limit', int $orderMode = 4): OrderPlanModel
    {
        return new OrderPlanModel(
            symbol: 'BTCUSDT',
            side: $side,
            orderType: $orderType,
            openType: 'isolated',
            orderMode: $orderMode,
            entry: 25000.0,
            stop: 24000.0,
            takeProfit: 26000.0,
            size: 10,
            leverage: 3,
            pricePrecision: 2,
            contractSize: 0.001,
        );
    }
}
