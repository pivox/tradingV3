<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use App\TradingCore\Execution\Fake\FakeExecutionPort;
use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\OrderPlan\Service\OrderPlanValidator;
use App\TradingCore\SlTp\Dto\LiquidationCheckResult;
use App\TradingCore\SlTp\Dto\ProtectionPlan;
use App\TradingCore\SlTp\Dto\StopLossResult;
use App\TradingCore\SlTp\Dto\TakeProfitResult;
use App\TradingCore\SlTp\Enum\ProtectionPlanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeExecutionPort::class)]
final class FakeExecutionPortTest extends TestCase
{
    public function testDryRunValidPlanReturnsSimulatedDryRunResult(): void
    {
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $result = (new FakeExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::DryRun, $result->status);
        self::assertSame('CID-ABC', $result->clientOrderId);
        self::assertSame('FAKE-CID-ABC', $result->exchangeOrderId);
        self::assertTrue($result->metadata['simulated']);
        self::assertSame('fake_paper', $result->metadata['gateway']);
        self::assertSame('dry_run', $result->metadata['mode']);
        self::assertTrue($result->metadata['dry_run']);
    }

    public function testDryRunInvalidPlanIsRejectedWithoutExecuting(): void
    {
        $request = ExecutionRequest::forPlan($this->invalidPlan(), ExecutionMode::DryRun);

        $result = (new FakeExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertNull($result->exchangeOrderId);
        self::assertSame('order_plan_not_executable', $result->metadata['reject_reason']);
        self::assertContains('protection_plan_missing', $result->metadata['invalid_reasons']);
    }

    public function testLiveModeIsRefusedByFakeGateway(): void
    {
        // forPlan() allows a live request only for an executable plan; the fake
        // gateway must still refuse to route it anywhere.
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::Live);

        $result = (new FakeExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertNull($result->exchangeOrderId);
        self::assertSame('live_not_supported_by_fake_gateway', $result->metadata['reject_reason']);
    }

    public function testFakeOrderIdIsDeterministicAndPortIsStateless(): void
    {
        $port = new FakeExecutionPort();
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $first = $port->execute($request);
        $second = $port->execute($request);

        // Same plan -> same fake exchange order id; no accumulated state -> equal results.
        self::assertSame('FAKE-CID-ABC', $first->exchangeOrderId);
        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        self::assertEquals($first, $second);
    }

    public function testPreservesClientOrderIdAndIdempotencyKey(): void
    {
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $result = (new FakeExecutionPort())->execute($request);

        self::assertSame('CID-ABC', $result->clientOrderId);
        self::assertSame('CID-ABC', $result->metadata['client_order_id']);
        self::assertSame('decision:BTCUSDT:long', $result->metadata['idempotency_key']);
    }

    public function testPreservesIncomingRequestMetadata(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            ['run_id' => 'run-123', 'correlation_id' => 'corr-9'],
        );

        $result = (new FakeExecutionPort())->execute($request);

        self::assertSame('run-123', $result->metadata['run_id']);
        self::assertSame('corr-9', $result->metadata['correlation_id']);
    }

    public function testCallerCannotOverrideGatewayAuthoritativeMetadata(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            ['gateway' => 'spoofed', 'simulated' => false],
        );

        $result = (new FakeExecutionPort())->execute($request);

        self::assertSame('fake_paper', $result->metadata['gateway']);
        self::assertTrue($result->metadata['simulated']);
    }

    // --- fixtures ---

    private function executablePlan(): OrderPlan
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
            clientOrderId: 'CID-ABC',
            idempotencyKey: 'decision:BTCUSDT:long',
        );

        return $plan->withValidation((new OrderPlanValidator())->validate($plan));
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
            quantity: 12.0,
            leverage: 5,
            protectionPlan: null,
            clientOrderId: 'CID-ABC',
            idempotencyKey: 'decision:BTCUSDT:long',
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
            isValid: true,
            status: ProtectionPlanStatus::Valid,
        );
    }
}
