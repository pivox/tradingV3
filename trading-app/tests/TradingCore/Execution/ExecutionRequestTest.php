<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\OrderPlan\Dto\OrderPlanValidationResult;
use App\TradingCore\OrderPlan\Enum\OrderPlanStatus;
use App\TradingCore\SlTp\Dto\LiquidationCheckResult;
use App\TradingCore\SlTp\Dto\ProtectionPlan;
use App\TradingCore\SlTp\Dto\StopLossResult;
use App\TradingCore\SlTp\Enum\ProtectionPlanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExecutionRequest::class)]
final class ExecutionRequestTest extends TestCase
{
    public function testLiveExecutionRequestRequiresExecutableOrderPlan(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Live execution requires an executable order plan.');

        ExecutionRequest::forPlan($this->invalidPlan(), ExecutionMode::Live);
    }

    public function testDryRunExecutionRequestCanCarryInvalidPlanForInspection(): void
    {
        $request = ExecutionRequest::forPlan($this->invalidPlan(), ExecutionMode::DryRun);

        self::assertSame(ExecutionMode::DryRun, $request->mode);
        self::assertFalse($request->orderPlan->validation->isExecutable);
    }

    public function testLiveExecutionRejectsPlanWithForgedExecutableValidation(): void
    {
        $forgedPlan = new OrderPlan(
            symbol: 'BTCUSDT',
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'perpetual',
            side: 'long',
            orderType: 'limit',
            marginMode: 'isolated',
            timeInForce: 'gtc',
            entryPrice: 100.0,
            quantity: 1.0,
            leverage: 5,
            clientOrderId: 'CID123',
            idempotencyKey: 'decision:BTCUSDT:long',
            validation: new OrderPlanValidationResult(
                status: OrderPlanStatus::Valid,
                isExecutable: true,
            ),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Live execution requires an executable order plan.');

        ExecutionRequest::forPlan($forgedPlan, ExecutionMode::Live);
    }

    public function testLiveExecutionRequestUsesFreshValidationResult(): void
    {
        $stalePlan = new OrderPlan(
            symbol: 'BTCUSDT',
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'perpetual',
            side: 'long',
            orderType: 'limit',
            marginMode: 'isolated',
            timeInForce: 'gtc',
            entryPrice: 100.0,
            quantity: 1.0,
            leverage: 5,
            protectionPlan: $this->protectionPlan(),
            clientOrderId: 'CID123',
            idempotencyKey: 'decision:BTCUSDT:long',
            validation: new OrderPlanValidationResult(
                status: OrderPlanStatus::Invalid,
                isExecutable: false,
                invalidReasons: ['stale_validation'],
            ),
        );

        $request = ExecutionRequest::forPlan($stalePlan, ExecutionMode::Live);

        self::assertTrue($request->orderPlan->validation->isExecutable);
        self::assertSame([], $request->orderPlan->validation->invalidReasons);
    }

    private function invalidPlan(): OrderPlan
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
            quantity: 1.0,
            leverage: 5,
            validation: new OrderPlanValidationResult(
                status: OrderPlanStatus::Invalid,
                isExecutable: false,
                invalidReasons: ['protection_plan_missing'],
            ),
        );
    }

    private function protectionPlan(): ProtectionPlan
    {
        return new ProtectionPlan(
            stopLoss: new StopLossResult(
                stopPrice: 98.0,
                stopPct: 0.02,
                stopDistance: 2.0,
                stopSource: 'pivot',
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
