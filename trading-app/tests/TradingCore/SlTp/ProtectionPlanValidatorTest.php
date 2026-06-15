<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\SlTp;

use App\TradingCore\SlTp\Dto\LiquidationCheckResult;
use App\TradingCore\SlTp\Dto\ProtectionPlan;
use App\TradingCore\SlTp\Dto\StopLossResult;
use App\TradingCore\SlTp\Dto\TakeProfitResult;
use App\TradingCore\SlTp\Enum\ProtectionPlanStatus;
use App\TradingCore\SlTp\Service\ProtectionPlanValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtectionPlanValidator::class)]
#[CoversClass(ProtectionPlan::class)]
#[CoversClass(ProtectionPlanStatus::class)]
final class ProtectionPlanValidatorTest extends TestCase
{
    public function testInvalidatesPlanWithoutStopLoss(): void
    {
        $validator = new ProtectionPlanValidator();

        $plan = $validator->validate(
            stopLoss: null,
            takeProfit: $this->takeProfit(),
            liquidationCheck: $this->safeLiquidation(),
        );

        self::assertFalse($plan->isValid);
        self::assertSame(ProtectionPlanStatus::Invalid, $plan->status);
        self::assertContains('stop_loss_missing', $plan->invalidReasons);
    }

    public function testInvalidatesPlanWhenStopLossIsNotFullSize(): void
    {
        $validator = new ProtectionPlanValidator();

        $plan = $validator->validate(
            stopLoss: $this->stopLoss(isFullSize: false),
            takeProfit: $this->takeProfit(),
            liquidationCheck: $this->safeLiquidation(),
        );

        self::assertFalse($plan->isValid);
        self::assertContains('stop_loss_not_full_size', $plan->invalidReasons);
    }

    public function testInvalidatesPlanWhenStopPctIsNotPositive(): void
    {
        $validator = new ProtectionPlanValidator();

        $plan = $validator->validate(
            stopLoss: $this->stopLoss(stopPct: 0.0),
            takeProfit: $this->takeProfit(),
            liquidationCheck: $this->safeLiquidation(),
        );

        self::assertFalse($plan->isValid);
        self::assertContains('stop_pct_not_positive', $plan->invalidReasons);
    }

    public function testInvalidatesPlanWhenLiquidationGuardIsUnsafe(): void
    {
        $validator = new ProtectionPlanValidator();

        $plan = $validator->validate(
            stopLoss: $this->stopLoss(),
            takeProfit: $this->takeProfit(),
            liquidationCheck: new LiquidationCheckResult(
                isSafe: false,
                liquidationPrice: 90.0,
                liquidationDistancePct: 0.1,
                stopToLiquidationRatio: 2.0,
                warnings: [],
                reasonIfUnsafe: 'liquidation_distance_below_min_ratio',
                metadata: [],
            ),
        );

        self::assertFalse($plan->isValid);
        self::assertContains('liquidation_guard_unsafe', $plan->invalidReasons);
    }

    public function testValidatesPlanWithFullSizeStopCoherentTpAndSafeLiquidation(): void
    {
        $validator = new ProtectionPlanValidator();

        $plan = $validator->validate(
            stopLoss: $this->stopLoss(),
            takeProfit: $this->takeProfit(),
            liquidationCheck: $this->safeLiquidation(),
        );

        self::assertTrue($plan->isValid);
        self::assertSame(ProtectionPlanStatus::Valid, $plan->status);
        self::assertSame([], $plan->invalidReasons);
    }

    public function testWarnsWhenTakeProfitNetRIsNotPositive(): void
    {
        $validator = new ProtectionPlanValidator();

        $plan = $validator->validate(
            stopLoss: $this->stopLoss(),
            takeProfit: new TakeProfitResult(
                tp1Price: 100.2,
                tp2Price: null,
                expectedR: 0.2,
                expectedNetR: -0.4,
                tpPolicyApplied: 'r_multiple',
                warnings: ['expectedNetR is not positive after fees/spread/slippage.'],
                metadata: [],
            ),
            liquidationCheck: $this->safeLiquidation(),
        );

        self::assertTrue($plan->isValid);
        self::assertContains('take_profit_net_r_not_positive', $plan->warnings);
    }

    private function stopLoss(bool $isFullSize = true, float $stopPct = 0.05): StopLossResult
    {
        return new StopLossResult(
            stopPrice: 95.0,
            stopPct: $stopPct,
            stopDistance: 5.0,
            stopSource: 'atr',
            isFullSize: $isFullSize,
            warnings: [],
            metadata: [],
        );
    }

    private function takeProfit(): TakeProfitResult
    {
        return new TakeProfitResult(
            tp1Price: 107.0,
            tp2Price: 109.0,
            expectedR: 1.4,
            expectedNetR: 1.2,
            tpPolicyApplied: 'r_multiple',
            warnings: [],
            metadata: [],
        );
    }

    private function safeLiquidation(): LiquidationCheckResult
    {
        return new LiquidationCheckResult(
            isSafe: true,
            liquidationPrice: 80.0,
            liquidationDistancePct: 0.2,
            stopToLiquidationRatio: 4.0,
            warnings: [],
            reasonIfUnsafe: null,
            metadata: [],
        );
    }
}
