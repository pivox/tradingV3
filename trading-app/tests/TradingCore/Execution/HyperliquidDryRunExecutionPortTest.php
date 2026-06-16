<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use App\TradingCore\Execution\Hyperliquid\HyperliquidDryRunExecutionPort;
use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\OrderPlan\Service\OrderPlanValidator;
use App\TradingCore\SlTp\Dto\LiquidationCheckResult;
use App\TradingCore\SlTp\Dto\ProtectionPlan;
use App\TradingCore\SlTp\Dto\StopLossResult;
use App\TradingCore\SlTp\Dto\TakeProfitResult;
use App\TradingCore\SlTp\Enum\ProtectionPlanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidDryRunExecutionPort::class)]
final class HyperliquidDryRunExecutionPortTest extends TestCase
{
    public function testDryRunValidHyperliquidPlanReturnsSimulatedDryRunResult(): void
    {
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::DryRun, $result->status);
        self::assertSame('CID-HL-1', $result->clientOrderId);
        self::assertSame('HYPERLIQUID-DRYRUN-CID-HL-1', $result->exchangeOrderId);
        self::assertSame('hyperliquid', $result->metadata['gateway']);
        self::assertSame('dry_run', $result->metadata['mode']);
        self::assertTrue($result->metadata['simulated']);
        self::assertTrue($result->metadata['protection_present']);
    }

    public function testDryRunResultAdvertisesNoHttpAndNoExchangeCall(): void
    {
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertTrue($result->metadata['no_http']);
        self::assertTrue($result->metadata['no_exchange_call']);
        // The plan fields are echoed for audit/preview comparison against future live payloads.
        self::assertSame('BTCUSDT', $result->metadata['symbol']);
        self::assertSame('long', $result->metadata['side']);
        self::assertSame('limit', $result->metadata['order_type']);
        self::assertSame(100.0, $result->metadata['entry_price']);
        self::assertSame(12.0, $result->metadata['quantity']);
        self::assertSame(5, $result->metadata['leverage']);
    }

    public function testLiveModeIsRefused(): void
    {
        // forPlan() allows a live request only for an executable plan; the Hyperliquid dry-run
        // port must still refuse to route it anywhere (and never sign).
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::Live);

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertNull($result->exchangeOrderId);
        self::assertSame('live_not_supported_by_hyperliquid_dry_run', $result->metadata['reject_reason']);
    }

    public function testRejectsPlanForAnotherExchange(): void
    {
        $request = ExecutionRequest::forPlan($this->executablePlan(exchange: 'okx'), ExecutionMode::DryRun);

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertNull($result->exchangeOrderId);
        self::assertSame('wrong_exchange_for_hyperliquid_dry_run', $result->metadata['reject_reason']);
        self::assertSame('okx', $result->metadata['plan_exchange']);
    }

    public function testRejectsUnsupportedMarketType(): void
    {
        $request = ExecutionRequest::forPlan($this->executablePlan(marketType: 'spot'), ExecutionMode::DryRun);

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertNull($result->exchangeOrderId);
        self::assertSame('market_type_not_supported_by_hyperliquid_dry_run', $result->metadata['reject_reason']);
        self::assertSame('spot', $result->metadata['plan_market_type']);
    }

    public function testRejectsNonExecutablePlanWithoutExecuting(): void
    {
        $request = ExecutionRequest::forPlan($this->invalidPlan(), ExecutionMode::DryRun);

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertNull($result->exchangeOrderId);
        self::assertSame('order_plan_not_executable', $result->metadata['reject_reason']);
        self::assertContains('protection_plan_missing', $result->metadata['invalid_reasons']);
    }

    public function testDryRunOrderIdIsDeterministicAndPortIsStateless(): void
    {
        $port = new HyperliquidDryRunExecutionPort();
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $first = $port->execute($request);
        $second = $port->execute($request);

        self::assertSame('HYPERLIQUID-DRYRUN-CID-HL-1', $first->exchangeOrderId);
        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        self::assertEquals($first, $second);
    }

    public function testPreservesClientOrderIdAndIdempotencyKey(): void
    {
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame('CID-HL-1', $result->clientOrderId);
        self::assertSame('CID-HL-1', $result->metadata['client_order_id']);
        self::assertSame('decision:BTCUSDT:long', $result->metadata['idempotency_key']);
    }

    public function testPreservesIncomingRequestMetadata(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            ['run_id' => 'run-77', 'correlation_id' => 'corr-9'],
        );

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame('run-77', $result->metadata['run_id']);
        self::assertSame('corr-9', $result->metadata['correlation_id']);
    }

    public function testCallerCannotOverrideGatewayAuthoritativeMetadata(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            ['gateway' => 'spoofed', 'simulated' => false, 'no_http' => false, 'no_exchange_call' => false],
        );

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame('hyperliquid', $result->metadata['gateway']);
        self::assertTrue($result->metadata['simulated']);
        self::assertTrue($result->metadata['no_http']);
        self::assertTrue($result->metadata['no_exchange_call']);
    }

    public function testCallerCannotSpoofRejectReasonOnLivePath(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::Live,
            ['reject_reason' => 'caller_spoofed'],
        );

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame('live_not_supported_by_hyperliquid_dry_run', $result->metadata['reject_reason']);
    }

    // --- fixtures ---

    private function executablePlan(string $exchange = 'hyperliquid', string $marketType = 'perpetual'): OrderPlan
    {
        $plan = new OrderPlan(
            symbol: 'BTCUSDT',
            profile: 'scalper_micro',
            exchange: $exchange,
            marketType: $marketType,
            side: 'long',
            orderType: 'limit',
            marginMode: 'isolated',
            timeInForce: 'gtc',
            entryPrice: 100.0,
            quantity: 12.0,
            leverage: 5,
            protectionPlan: $this->protectionPlan(),
            clientOrderId: 'CID-HL-1',
            idempotencyKey: 'decision:BTCUSDT:long',
        );

        return $plan->withValidation((new OrderPlanValidator())->validate($plan));
    }

    private function invalidPlan(): OrderPlan
    {
        return new OrderPlan(
            symbol: 'BTCUSDT',
            profile: 'scalper_micro',
            exchange: 'hyperliquid',
            marketType: 'perpetual',
            side: 'long',
            orderType: 'limit',
            marginMode: 'isolated',
            timeInForce: 'gtc',
            entryPrice: 100.0,
            quantity: 12.0,
            leverage: 5,
            protectionPlan: null,
            clientOrderId: 'CID-HL-1',
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
