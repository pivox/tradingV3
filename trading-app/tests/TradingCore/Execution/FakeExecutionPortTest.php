<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use App\TradingCore\Execution\Fake\FakeExecutionPort;
use App\TradingCore\Execution\Fake\FakeExecutionScenario;
use App\TradingCore\Execution\Fake\FakeExecutionScenarioFixtures;
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
#[CoversClass(FakeExecutionScenario::class)]
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
            ['gateway' => 'spoofed', 'simulated' => false, 'dry_run' => false],
        );

        $result = (new FakeExecutionPort())->execute($request);

        self::assertSame('fake_paper', $result->metadata['gateway']);
        self::assertTrue($result->metadata['simulated']);
        // dry_run is a result-path field: the gateway value must overwrite the caller's.
        self::assertTrue($result->metadata['dry_run']);
    }

    public function testCallerCannotSpoofRejectReasonOnLivePath(): void
    {
        // reject_reason carried by the incoming metadata must not survive: the gateway's
        // own result reason is authoritative.
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::Live,
            ['reject_reason' => 'caller_spoofed'],
        );

        $result = (new FakeExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame('live_not_supported_by_fake_gateway', $result->metadata['reject_reason']);
    }

    public function testScenarioRejectsUnknownOrderOutcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported fake execution order outcome.');

        new FakeExecutionScenario(
            name: 'typo',
            orderOutcome: 'acccepted',
            fillRatio: 0.0,
            protectionOutcome: 'not_requested',
        );
    }

    public function testScenarioRejectsUnknownProtectionOutcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported fake execution protection outcome.');

        new FakeExecutionScenario(
            name: 'typo',
            orderOutcome: 'accepted',
            fillRatio: 0.0,
            protectionOutcome: 'attched',
        );
    }

    public function testDemoRecipeFullFillAttachesStopSuccessfully(): void
    {
        $port = new FakeExecutionPort(scenario: FakeExecutionScenarioFixtures::fullFillStopAttachSuccess());
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $result = $port->execute($request);

        self::assertSame(ExecutionStatus::Accepted, $result->status);
        self::assertSame('filled', $result->raw['order']['status']);
        self::assertSame('FAKE-FILL-CID-ABC-001', $result->raw['fills'][0]['fill_id']);
        self::assertSame(12.0, $result->raw['fills'][0]['quantity']);
        self::assertSame('attached', $result->raw['protection']['status']);
        self::assertSame(12.0, $result->raw['protection']['protected_quantity']);
        self::assertSame([], $result->metadata['quality_flags']);
    }

    public function testFullFillScenarioDoesNotRoundAboveRequestedQuantity(): void
    {
        $port = new FakeExecutionPort(scenario: FakeExecutionScenarioFixtures::fullFillStopAttachSuccess());
        $request = ExecutionRequest::forPlan($this->executablePlan(quantity: 1.123456789), ExecutionMode::DryRun);

        $result = $port->execute($request);
        $position = $port->resyncPosition('CID-ABC');

        self::assertSame(1.123456789, $result->raw['order']['filled_quantity']);
        self::assertSame(1.123456789, $result->raw['fills'][0]['quantity']);
        self::assertSame(1.123456789, $result->raw['protection']['protected_quantity']);
        self::assertSame(1.123456789, $position['quantity']);
    }

    public function testAcceptedScenarioReturnsAcceptedOrderWithoutFill(): void
    {
        $port = new FakeExecutionPort(scenario: FakeExecutionScenarioFixtures::orderAccepted());
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $result = $port->execute($request);

        self::assertSame(ExecutionStatus::Accepted, $result->status);
        self::assertSame('accepted', $result->raw['order']['status']);
        self::assertSame([], $result->raw['fills']);
        self::assertSame('not_requested', $result->raw['protection']['status']);
        self::assertFalse($result->metadata['demo_recipe_protected']);
    }

    public function testFullFillWithStopAttachFailureRequiresFailSafe(): void
    {
        $port = new FakeExecutionPort(scenario: FakeExecutionScenarioFixtures::fullFillStopAttachFailure());
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $result = $port->execute($request);

        self::assertSame(ExecutionStatus::Failed, $result->status);
        self::assertSame('filled', $result->raw['order']['status']);
        self::assertSame('failed', $result->raw['protection']['status']);
        self::assertSame('cancel_or_reduce_only_close_required', $result->raw['fail_safe']['action']);
        self::assertContains('protection_attach_failed', $result->metadata['quality_flags']);
        self::assertFalse($result->metadata['demo_recipe_protected']);
    }

    public function testPartialFillWithRejectedPartialStopIsFlagged(): void
    {
        $port = new FakeExecutionPort(scenario: FakeExecutionScenarioFixtures::partialFillStopRejected());
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $result = $port->execute($request);

        self::assertSame(ExecutionStatus::Failed, $result->status);
        self::assertSame('partially_filled', $result->raw['order']['status']);
        self::assertSame(6.0, $result->raw['fills'][0]['quantity']);
        self::assertSame('rejected', $result->raw['protection']['status']);
        self::assertSame(0.0, $result->raw['protection']['protected_quantity']);
        self::assertContains('partial_fill', $result->metadata['quality_flags']);
        self::assertContains('partial_stop_rejected', $result->metadata['quality_flags']);
    }

    public function testPartialFillScenarioDoesNotRoundTinyQuantityUpToFullSize(): void
    {
        $port = new FakeExecutionPort(scenario: FakeExecutionScenarioFixtures::partialFillStopRejected());
        $request = ExecutionRequest::forPlan($this->executablePlan(quantity: 0.00000001), ExecutionMode::DryRun);

        $result = $port->execute($request);

        self::assertSame('partially_filled', $result->raw['order']['status']);
        self::assertSame(0.000000005, $result->raw['order']['filled_quantity']);
        self::assertSame(0.000000005, $result->raw['fills'][0]['quantity']);
        self::assertLessThan($result->raw['order']['quantity'], $result->raw['order']['filled_quantity']);
    }

    public function testDuplicateClientOrderIdDoesNotCreateSecondFill(): void
    {
        $port = new FakeExecutionPort(scenario: FakeExecutionScenarioFixtures::fullFillStopAttachSuccess());
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $first = $port->execute($request);
        $second = $port->execute($request);

        self::assertSame(ExecutionStatus::Accepted, $first->status);
        self::assertSame(ExecutionStatus::Rejected, $second->status);
        self::assertSame('duplicate_client_order_id', $second->metadata['reject_reason']);
        self::assertSame('FAKE-CID-ABC', $second->metadata['previous_exchange_order_id']);
        self::assertSame(1, $port->fillCount());
    }

    public function testRestartResyncRestoresSimulatedOpenPosition(): void
    {
        $port = new FakeExecutionPort(scenario: FakeExecutionScenarioFixtures::fullFillStopAttachSuccess());
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $port->execute($request);
        $restarted = FakeExecutionPort::fromSnapshot($port->snapshot());

        $position = $restarted->resyncPosition('CID-ABC');

        self::assertSame('BTCUSDT', $position['symbol']);
        self::assertSame('long', $position['side']);
        self::assertSame(12.0, $position['quantity']);
        self::assertSame('protected', $position['protection_status']);
        self::assertSame('FAKE-CID-ABC', $position['exchange_order_id']);
    }

    public function testRestartKeepsScenarioDuplicateGuardWhenReplayingSameClientOrderId(): void
    {
        $port = new FakeExecutionPort(scenario: FakeExecutionScenarioFixtures::fullFillStopAttachSuccess());
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $port->execute($request);
        $restarted = FakeExecutionPort::fromSnapshot(
            $port->snapshot(),
            scenario: FakeExecutionScenarioFixtures::fullFillStopAttachSuccess(),
        );

        $result = $restarted->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame('duplicate_client_order_id', $result->metadata['reject_reason']);
        self::assertSame(1, $restarted->fillCount());
    }

    public function testCancelScenarioReturnsCancelledOrderWithoutFill(): void
    {
        $port = new FakeExecutionPort(scenario: FakeExecutionScenarioFixtures::cancelAcceptedOrder());
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $result = $port->execute($request);

        self::assertSame(ExecutionStatus::Skipped, $result->status);
        self::assertSame('cancelled', $result->raw['order']['status']);
        self::assertSame([], $result->raw['fills']);
        self::assertTrue($result->metadata['cancelled']);
    }

    public function testRejectedScenarioReturnsStructuredRejectReason(): void
    {
        $port = new FakeExecutionPort(scenario: FakeExecutionScenarioFixtures::orderRejected());
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $result = $port->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame('rejected', $result->raw['order']['status']);
        self::assertSame('fake_exchange_rejected_order', $result->metadata['reject_reason']);
        self::assertSame([], $result->raw['fills']);
    }

    // --- fixtures ---

    private function executablePlan(float $quantity = 12.0): OrderPlan
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
            quantity: $quantity,
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
