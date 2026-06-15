<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan;

use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\OrderPlan\Enum\OrderPlanStatus;
use App\TradingCore\OrderPlan\Service\OrderPlanValidator;
use App\TradingCore\SlTp\Dto\LiquidationCheckResult;
use App\TradingCore\SlTp\Dto\ProtectionPlan;
use App\TradingCore\SlTp\Dto\StopLossResult;
use App\TradingCore\SlTp\Dto\TakeProfitResult;
use App\TradingCore\SlTp\Enum\ProtectionPlanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderPlan::class)]
#[CoversClass(OrderPlanValidator::class)]
final class OrderPlanValidatorTest extends TestCase
{
    public function testRejectsPlanWithoutProtectionPlan(): void
    {
        $result = (new OrderPlanValidator())->validate($this->planWithoutProtection());

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertFalse($result->isExecutable);
        self::assertContains('protection_plan_missing', $result->invalidReasons);
    }

    public function testRejectsPlanWhenStopLossDoesNotCoverFullSize(): void
    {
        $protection = $this->protectionPlan(
            stopLoss: new StopLossResult(
                stopPrice: 98.0,
                stopPct: 0.02,
                stopDistance: 2.0,
                stopSource: 'pivot',
                isFullSize: false,
            ),
            isValid: true,
        );

        $result = (new OrderPlanValidator())->validate($this->plan(protectionPlan: $protection));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('stop_loss_not_full_size', $result->invalidReasons);
    }

    public function testRejectsPlanWhenStopPctIsNotPositive(): void
    {
        $protection = $this->protectionPlan(
            stopLoss: new StopLossResult(
                stopPrice: 100.0,
                stopPct: 0.0,
                stopDistance: 0.0,
                stopSource: 'risk',
                isFullSize: true,
            ),
            isValid: true,
        );

        $result = (new OrderPlanValidator())->validate($this->plan(protectionPlan: $protection));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('stop_pct_not_positive', $result->invalidReasons);
    }

    public function testRejectsNonPositiveExecutionFields(): void
    {
        $result = (new OrderPlanValidator())->validate($this->plan(
            entryPrice: 0.0,
            quantity: 0.0,
            leverage: 0,
        ));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('entry_price_not_positive', $result->invalidReasons);
        self::assertContains('quantity_not_positive', $result->invalidReasons);
        self::assertContains('leverage_not_positive', $result->invalidReasons);
    }

    public function testRejectsMissingRoutingFields(): void
    {
        $result = (new OrderPlanValidator())->validate($this->plan(
            symbol: '',
            profile: '',
            exchange: '',
            marketType: '',
            instrument: '',
        ));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('symbol_missing', $result->invalidReasons);
        self::assertContains('profile_missing', $result->invalidReasons);
        self::assertContains('exchange_missing', $result->invalidReasons);
        self::assertContains('market_type_missing', $result->invalidReasons);
        self::assertContains('instrument_missing', $result->invalidReasons);
    }

    public function testRejectsUnsupportedMarketType(): void
    {
        $result = (new OrderPlanValidator())->validate($this->plan(marketType: 'futures'));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('market_type_invalid', $result->invalidReasons);
    }

    public function testRejectsInvalidMarginModeAndTimeInForce(): void
    {
        $result = (new OrderPlanValidator())->validate($this->plan(
            marginMode: 'portfolio',
            timeInForce: 'maker_only',
        ));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('margin_mode_invalid', $result->invalidReasons);
        self::assertContains('time_in_force_invalid', $result->invalidReasons);
    }

    public function testRejectsNonFiniteExecutionFields(): void
    {
        $result = (new OrderPlanValidator())->validate($this->plan(
            entryPrice: INF,
            quantity: NAN,
            contractSize: -INF,
        ));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('entry_price_not_positive', $result->invalidReasons);
        self::assertContains('quantity_not_positive', $result->invalidReasons);
        self::assertContains('contract_size_not_positive', $result->invalidReasons);
    }

    public function testRejectsNonFiniteStopLossFields(): void
    {
        $protection = $this->protectionPlan(
            stopLoss: new StopLossResult(
                stopPrice: INF,
                stopPct: NAN,
                stopDistance: -INF,
                stopSource: 'pivot',
                isFullSize: true,
            ),
        );

        $result = (new OrderPlanValidator())->validate($this->plan(protectionPlan: $protection));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('stop_price_not_positive', $result->invalidReasons);
        self::assertContains('stop_pct_not_positive', $result->invalidReasons);
        self::assertContains('stop_distance_not_positive', $result->invalidReasons);
    }

    public function testRejectsLongStopLossAtOrAboveEntry(): void
    {
        $protection = $this->protectionPlan(
            stopLoss: new StopLossResult(
                stopPrice: 100.0,
                stopPct: 0.02,
                stopDistance: 2.0,
                stopSource: 'pivot',
                isFullSize: true,
            ),
        );

        $result = (new OrderPlanValidator())->validate($this->plan(protectionPlan: $protection));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('stop_loss_side_invalid', $result->invalidReasons);
    }

    public function testRejectsShortStopLossAtOrBelowEntry(): void
    {
        $protection = $this->protectionPlan(
            stopLoss: new StopLossResult(
                stopPrice: 100.0,
                stopPct: 0.02,
                stopDistance: 2.0,
                stopSource: 'pivot',
                isFullSize: true,
            ),
        );

        $result = (new OrderPlanValidator())->validate($this->plan(
            side: 'short',
            protectionPlan: $protection,
        ));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('stop_loss_side_invalid', $result->invalidReasons);
    }

    public function testRejectsPerpetualPlanWithoutLiquidationGuard(): void
    {
        $result = (new OrderPlanValidator())->validate($this->plan(
            protectionPlan: $this->protectionPlan(liquidationCheck: null),
        ));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('liquidation_guard_missing', $result->invalidReasons);
    }

    public function testRejectsPerpetualPlanWithUnsafeLiquidationGuard(): void
    {
        $result = (new OrderPlanValidator())->validate($this->plan(
            protectionPlan: $this->protectionPlan(
                liquidationCheck: new LiquidationCheckResult(
                    isSafe: false,
                    liquidationPrice: 99.0,
                    liquidationDistancePct: 0.01,
                    stopToLiquidationRatio: 0.5,
                    reasonIfUnsafe: 'stop_too_close_to_liquidation',
                ),
            ),
        ));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('liquidation_guard_unsafe', $result->invalidReasons);
    }

    public function testRejectsPerpetualPlanWithMissingLiquidationMetrics(): void
    {
        $result = (new OrderPlanValidator())->validate($this->plan(
            protectionPlan: $this->protectionPlan(
                liquidationCheck: new LiquidationCheckResult(
                    isSafe: true,
                    liquidationPrice: null,
                    liquidationDistancePct: null,
                    stopToLiquidationRatio: null,
                ),
            ),
        ));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('liquidation_guard_data_invalid', $result->invalidReasons);
    }

    public function testRejectsPerpetualPlanWithNonFiniteLiquidationMetrics(): void
    {
        $result = (new OrderPlanValidator())->validate($this->plan(
            protectionPlan: $this->protectionPlan(
                liquidationCheck: new LiquidationCheckResult(
                    isSafe: true,
                    liquidationPrice: INF,
                    liquidationDistancePct: NAN,
                    stopToLiquidationRatio: -INF,
                ),
            ),
        ));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('liquidation_guard_data_invalid', $result->invalidReasons);
    }

    public function testRejectsLongLiquidationPriceAtOrAboveStop(): void
    {
        $result = (new OrderPlanValidator())->validate($this->plan(
            protectionPlan: $this->protectionPlan(
                liquidationCheck: new LiquidationCheckResult(
                    isSafe: true,
                    liquidationPrice: 98.0,
                    liquidationDistancePct: 0.02,
                    stopToLiquidationRatio: 1.0,
                ),
            ),
        ));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('liquidation_price_not_beyond_stop', $result->invalidReasons);
    }

    public function testRejectsShortLiquidationPriceAtOrBelowStop(): void
    {
        $result = (new OrderPlanValidator())->validate($this->plan(
            side: 'short',
            protectionPlan: $this->protectionPlan(
                stopLoss: new StopLossResult(
                    stopPrice: 102.0,
                    stopPct: 0.02,
                    stopDistance: 2.0,
                    stopSource: 'pivot',
                    isFullSize: true,
                ),
                liquidationCheck: new LiquidationCheckResult(
                    isSafe: true,
                    liquidationPrice: 102.0,
                    liquidationDistancePct: 0.02,
                    stopToLiquidationRatio: 1.0,
                ),
            ),
        ));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('liquidation_price_not_beyond_stop', $result->invalidReasons);
    }

    public function testWithValidationPreservesAllFields(): void
    {
        $plan = new OrderPlan(
            symbol: 'BTCUSDT',
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'perpetual',
            side: 'long',
            orderType: 'limit',
            marginMode: 'isolated',
            timeInForce: 'gtc',
            entryPrice: 100.0,
            quantity: 12.0,
            leverage: 5,
            protectionPlan: $this->protectionPlan(),
            clientOrderId: 'CID123',
            idempotencyKey: 'key:BTCUSDT',
            metadata: ['foo' => 'bar'],
        );

        $validation = new \App\TradingCore\OrderPlan\Dto\OrderPlanValidationResult(
            status: OrderPlanStatus::Valid,
            isExecutable: true,
        );
        $updated = $plan->withValidation($validation);

        self::assertSame($plan->symbol, $updated->symbol);
        self::assertSame($plan->entryPrice, $updated->entryPrice);
        self::assertSame($plan->quantity, $updated->quantity);
        self::assertSame($plan->metadata, $updated->metadata);
        self::assertSame($plan->clientOrderId, $updated->clientOrderId);
        self::assertSame(OrderPlanStatus::Valid, $updated->validation->status);
    }

    public function testRejectsPlanWithoutClientOrderId(): void
    {
        $result = (new OrderPlanValidator())->validate($this->plan(clientOrderId: ''));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('client_order_id_missing', $result->invalidReasons);
    }

    public function testRejectsPlanWithoutIdempotencyKey(): void
    {
        $result = (new OrderPlanValidator())->validate($this->plan(idempotencyKey: ''));

        self::assertSame(OrderPlanStatus::Invalid, $result->status);
        self::assertContains('idempotency_key_missing', $result->invalidReasons);
    }

    public function testValidPlanRequiresFullSizeStopPositiveQuantityAndSafeProtection(): void
    {
        $result = (new OrderPlanValidator())->validate($this->plan());

        self::assertSame(OrderPlanStatus::Valid, $result->status);
        self::assertTrue($result->isExecutable);
        self::assertSame([], $result->invalidReasons);
    }

    private function plan(
        string $symbol = 'BTCUSDT',
        string $profile = 'scalper_micro',
        string $exchange = 'bitmart',
        string $marketType = 'perpetual',
        string $instrument = 'BTCUSDT',
        string $side = 'long',
        string $marginMode = 'isolated',
        string $timeInForce = 'gtc',
        ?ProtectionPlan $protectionPlan = null,
        float $entryPrice = 100.0,
        float $quantity = 12.0,
        int $leverage = 5,
        ?float $contractSize = null,
        ?string $clientOrderId = 'CID123',
        ?string $idempotencyKey = 'decision:BTCUSDT:long',
    ): OrderPlan {
        return new OrderPlan(
            symbol: $symbol,
            profile: $profile,
            exchange: $exchange,
            marketType: $marketType,
            side: $side,
            orderType: 'limit',
            marginMode: $marginMode,
            timeInForce: $timeInForce,
            entryPrice: $entryPrice,
            quantity: $quantity,
            leverage: $leverage,
            protectionPlan: $protectionPlan ?? $this->protectionPlan(),
            clientOrderId: $clientOrderId,
            idempotencyKey: $idempotencyKey,
            contractSize: $contractSize,
            instrument: $instrument,
        );
    }

    private function planWithoutProtection(): OrderPlan
    {
        return new OrderPlan(
            symbol: 'BTCUSDT',
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'perpetual',
            side: 'long',
            orderType: 'limit',
            marginMode: 'isolated',
            timeInForce: 'gtc',
            entryPrice: 100.0,
            quantity: 12.0,
            leverage: 5,
            protectionPlan: null,
        );
    }

    private function protectionPlan(
        ?StopLossResult $stopLoss = null,
        ?LiquidationCheckResult $liquidationCheck = new LiquidationCheckResult(
            isSafe: true,
            liquidationPrice: 80.0,
            liquidationDistancePct: 0.20,
            stopToLiquidationRatio: 0.1,
        ),
        bool $isValid = true,
    ): ProtectionPlan {
        return new ProtectionPlan(
            stopLoss: $stopLoss ?? new StopLossResult(
                stopPrice: 98.0,
                stopPct: 0.02,
                stopDistance: 2.0,
                stopSource: 'pivot',
                isFullSize: true,
            ),
            takeProfit: new TakeProfitResult(
                tp1Price: 103.0,
                tp2Price: null,
                expectedR: 1.5,
                expectedNetR: 1.4,
                tpPolicyApplied: 'r_multiple',
            ),
            liquidationCheck: $liquidationCheck,
            isValid: $isValid,
            status: $isValid ? ProtectionPlanStatus::Valid : ProtectionPlanStatus::Invalid,
            invalidReasons: $isValid ? [] : ['liquidation_guard_unsafe'],
        );
    }
}
