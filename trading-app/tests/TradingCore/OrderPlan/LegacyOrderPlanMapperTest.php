<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan;

use App\Provider\Context\ExchangeContext;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\TradeEntry\OrderPlan\OrderPlanModel as LegacyOrderPlanModel;
use App\TradeEntry\Types\Side;
use App\TradingCore\OrderPlan\Enum\OrderPlanStatus;
use App\TradingCore\OrderPlan\Mapper\LegacyOrderPlanMapper;
use App\TradingCore\SlTp\Dto\LiquidationCheckResult;
use App\TradingCore\SlTp\Dto\ProtectionPlan;
use App\TradingCore\SlTp\Dto\StopLossResult;
use App\TradingCore\SlTp\Enum\ProtectionPlanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyOrderPlanMapper::class)]
final class LegacyOrderPlanMapperTest extends TestCase
{
    public function testMapsLegacyOrderPlanWithoutChangingExecutionValues(): void
    {
        $legacy = new LegacyOrderPlanModel(
            symbol: 'BTCUSDT',
            side: Side::Long,
            orderType: 'limit',
            openType: 'isolated',
            orderMode: 4,
            entry: 100.25,
            stop: 98.0,
            takeProfit: 104.0,
            size: 17,
            leverage: 6,
            pricePrecision: 2,
            contractSize: 0.001,
            exchangeContext: new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL),
        );

        $plan = (new LegacyOrderPlanMapper())->fromLegacy(
            legacy: $legacy,
            profile: 'scalper_micro',
            decisionKey: 'bitmart:perpetual:BTCUSDT:1m:1764160440:long:scalper_micro:v1',
            protectionPlan: $this->protectionPlan(),
        );

        self::assertSame('BTCUSDT', $plan->symbol);
        self::assertSame('scalper_micro', $plan->profile);
        self::assertSame('bitmart', $plan->exchange);
        self::assertSame('perpetual', $plan->marketType);
        self::assertSame('long', $plan->side);
        self::assertSame('limit', $plan->orderType);
        self::assertSame('post_only', $plan->timeInForce);
        self::assertSame(100.25, $plan->entryPrice);
        self::assertSame(17.0, $plan->quantity);
        self::assertSame(6, $plan->leverage);
        self::assertSame(2, $plan->pricePrecision);
        self::assertSame(0.001, $plan->contractSize);
        self::assertSame('bitmart:perpetual:BTCUSDT:1m:1764160440:long:scalper_micro:v1', $plan->decisionKey);
        self::assertSame('bitmart:perpetual:BTCUSDT:1m:1764160440:long:scalper_micro:v1', $plan->idempotencyKey);
        self::assertSame('CIDB6B3948EB6D29D505D7CCCA3FB9A9', $plan->clientOrderId);
        self::assertSame(OrderPlanStatus::Valid, $plan->validation->status);
        self::assertTrue($plan->validation->isExecutable);
    }

    public function testDoesNotInventProtectionWhenLegacyPlanHasOnlyScalarStops(): void
    {
        $legacy = new LegacyOrderPlanModel(
            symbol: 'ETHUSDT',
            side: Side::Short,
            orderType: 'market',
            openType: 'isolated',
            orderMode: 1,
            entry: 2000.0,
            stop: 2040.0,
            takeProfit: 1940.0,
            size: 3,
            leverage: 4,
            pricePrecision: 2,
            contractSize: 0.01,
        );

        $plan = (new LegacyOrderPlanMapper())->fromLegacy(
            legacy: $legacy,
            profile: 'regular',
            decisionKey: null,
            protectionPlan: null,
        );

        self::assertNull($plan->protectionPlan);
        self::assertSame('short', $plan->side);
        self::assertSame(OrderPlanStatus::Invalid, $plan->validation->status);
        self::assertContains('protection_plan_missing', $plan->validation->invalidReasons);
        self::assertContains('client_order_id_missing', $plan->validation->invalidReasons);
        self::assertContains('idempotency_key_missing', $plan->validation->invalidReasons);
        self::assertSame(2040.0, $plan->metadata['legacy_stop']);
        self::assertSame(1940.0, $plan->metadata['legacy_take_profit']);
    }

    private function protectionPlan(): ProtectionPlan
    {
        return new ProtectionPlan(
            stopLoss: new StopLossResult(
                stopPrice: 98.0,
                stopPct: 0.02,
                stopDistance: 2.0,
                stopSource: 'legacy_mapped',
                isFullSize: true,
            ),
            takeProfit: null,
            liquidationCheck: new LiquidationCheckResult(
                isSafe: true,
                liquidationPrice: 80.0,
                liquidationDistancePct: 0.20,
                stopToLiquidationRatio: 0.1,
            ),
            isValid: true,
            status: ProtectionPlanStatus::Valid,
        );
    }
}
