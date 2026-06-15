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
        ?ProtectionPlan $protectionPlan = null,
        float $entryPrice = 100.0,
        float $quantity = 12.0,
        int $leverage = 5,
        ?string $clientOrderId = 'CID123',
        ?string $idempotencyKey = 'decision:BTCUSDT:long',
    ): OrderPlan {
        return new OrderPlan(
            symbol: 'BTCUSDT',
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'perpetual',
            side: 'long',
            orderType: 'limit',
            marginMode: 'isolated',
            timeInForce: 'gtc',
            entryPrice: $entryPrice,
            quantity: $quantity,
            leverage: $leverage,
            protectionPlan: $protectionPlan ?? $this->protectionPlan(),
            clientOrderId: $clientOrderId,
            idempotencyKey: $idempotencyKey,
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
            liquidationCheck: new LiquidationCheckResult(
                isSafe: true,
                liquidationPrice: 80.0,
                liquidationDistancePct: 0.20,
                stopToLiquidationRatio: 0.1,
            ),
            isValid: $isValid,
            status: $isValid ? ProtectionPlanStatus::Valid : ProtectionPlanStatus::Invalid,
            invalidReasons: $isValid ? [] : ['liquidation_guard_unsafe'],
        );
    }
}
