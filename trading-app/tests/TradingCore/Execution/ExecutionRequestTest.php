<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\OrderPlan\Dto\OrderPlanValidationResult;
use App\TradingCore\OrderPlan\Enum\OrderPlanStatus;
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
}
