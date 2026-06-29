<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\Common\Enum\Exchange;
use App\Exchange\Readiness\ExchangePrivateObservabilityStatus;
use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use App\TradingCore\Execution\Okx\OkxDryRunExecutionPort;
use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\OrderPlan\Service\OrderPlanValidator;
use App\TradingCore\SlTp\Dto\LiquidationCheckResult;
use App\TradingCore\SlTp\Dto\ProtectionPlan;
use App\TradingCore\SlTp\Dto\StopLossResult;
use App\TradingCore\SlTp\Dto\TakeProfitResult;
use App\TradingCore\SlTp\Enum\ProtectionPlanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxDryRunExecutionPort::class)]
final class OkxDryRunExecutionPortTest extends TestCase
{
    public function testDryRunValidOkxPlanReturnsSimulatedDryRunResult(): void
    {
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::DryRun, $result->status);
        self::assertSame('CID-OKX-1', $result->clientOrderId);
        self::assertSame('OKX-DRYRUN-CID-OKX-1', $result->exchangeOrderId);
        self::assertSame('okx', $result->metadata['gateway']);
        self::assertSame('dry_run', $result->metadata['mode']);
        self::assertTrue($result->metadata['simulated']);
        self::assertTrue($result->metadata['protection_present']);
    }

    public function testDryRunResultAdvertisesNoHttpAndNoPrivatePost(): void
    {
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertTrue($result->metadata['no_http']);
        self::assertTrue($result->metadata['no_private_post']);
        // The plan fields are echoed for audit/preview comparison against future live payloads.
        self::assertSame('BTCUSDT', $result->metadata['symbol']);
        self::assertSame('long', $result->metadata['side']);
        self::assertSame('limit', $result->metadata['order_type']);
        self::assertSame(100.0, $result->metadata['entry_price']);
        self::assertSame(12.0, $result->metadata['quantity']);
        self::assertSame(5, $result->metadata['leverage']);
    }

    public function testDryRunBuildsRedactedOkxSubmitLeverageAndProtectionPayloads(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(instrument: 'BTC-USDT-SWAP', clientOrderId: 'CIDOKX1'),
            ExecutionMode::DryRun,
            [
                'environment' => 'demo',
                'allowed_symbols' => ['BTCUSDT'],
                'max_notional' => 2000.0,
                'max_leverage' => 10,
                'OKX_DEMO_API_KEY' => 'demo-secret-key',
            ],
            new \DateTimeImmutable('2026-06-30T01:15:00+00:00'),
        );

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::DryRun, $result->status);
        self::assertTrue($result->metadata['local_dry_run_ready']);
        self::assertSame('demo', $result->metadata['environment']);
        self::assertSame('local_dry_run_ready', $result->metadata['readiness_level']);
        self::assertTrue($result->raw['okx_dry_run']['no_http']);
        self::assertSame('/api/v5/account/set-leverage', $result->raw['okx_dry_run']['requests'][0]['path']);
        self::assertSame([
            'instId' => 'BTC-USDT-SWAP',
            'lever' => '5',
            'mgnMode' => 'isolated',
            'posSide' => 'long',
        ], $result->raw['okx_dry_run']['requests'][0]['body']);
        self::assertSame('/api/v5/account/set-leverage', $result->raw['okx_dry_run']['requests'][1]['path']);
        self::assertSame('short', $result->raw['okx_dry_run']['requests'][1]['body']['posSide']);
        self::assertSame('/api/v5/trade/order', $result->raw['okx_dry_run']['requests'][2]['path']);
        self::assertSame([
            'instId' => 'BTC-USDT-SWAP',
            'tdMode' => 'isolated',
            'clOrdId' => 'CIDOKX1',
            'side' => 'buy',
            'posSide' => 'long',
            'ordType' => 'limit',
            'sz' => '12',
            'reduceOnly' => 'false',
            'px' => '100',
        ], $result->raw['okx_dry_run']['requests'][2]['body']);
        self::assertSame('/api/v5/trade/order-algo', $result->raw['okx_dry_run']['requests'][3]['path']);
        self::assertSame('slTriggerPx', array_key_first(array_intersect_key(
            $result->raw['okx_dry_run']['requests'][3]['body'],
            ['slTriggerPx' => true],
        )));
        self::assertSame('98', $result->raw['okx_dry_run']['requests'][3]['body']['slTriggerPx']);
        self::assertSame('/api/v5/trade/order-algo', $result->raw['okx_dry_run']['requests'][4]['path']);
        self::assertSame('103', $result->raw['okx_dry_run']['requests'][4]['body']['tpTriggerPx']);

        $encoded = json_encode([$result->metadata, $result->raw], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('demo-secret-key', $encoded);
        self::assertStringContainsString('[redacted]', $encoded);
    }

    public function testDryRunRejectsMainnetEnvironmentBeforePayloadSerialization(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            ['environment' => 'mainnet'],
        );

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame('mainnet_environment_forbidden_for_okx_dry_run', $result->metadata['reject_reason']);
        self::assertSame([], $result->raw);
    }

    public function testDryRunRejectsSymbolOutsideWhitelist(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            ['allowed_symbols' => ['ETHUSDT']],
        );

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame('demo_trading_safety_blocked', $result->metadata['reject_reason']);
        self::assertContains('requested_symbol_or_market_not_allowed', $result->metadata['safety_decision']['blocking_errors']);
    }

    public function testDryRunRejectsNotionalAboveConfiguredCap(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            ['max_notional' => 1000.0],
        );

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame('demo_trading_safety_blocked', $result->metadata['reject_reason']);
        self::assertContains('max_notional_exceeded', $result->metadata['safety_decision']['blocking_errors']);
    }

    public function testDryRunRejectsLeverageAboveConfiguredCap(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            ['max_leverage' => 4],
        );

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame('leverage_cap_exceeded', $result->metadata['reject_reason']);
        self::assertSame(5, $result->metadata['leverage']);
        self::assertSame(4, $result->metadata['max_leverage']);
    }

    public function testDryRunKeepsPrivateObservabilityInformativeOnly(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            [
                'environment' => 'demo',
                'private_observability_status' => ExchangePrivateObservabilityStatus::absent(Exchange::OKX, 'demo'),
            ],
        );

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::DryRun, $result->status);
        self::assertTrue($result->metadata['private_observability_decision']['allowed']);
        self::assertIsArray($result->metadata['private_observability_status']);
        self::assertContains(
            'private_observability_absent_for_dry_run',
            $result->metadata['private_observability_decision']['warnings'],
        );
    }

    public function testLiveModeIsRefused(): void
    {
        // forPlan() allows a live request only for an executable plan; the OKX dry-run
        // port must still refuse to route it anywhere.
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::Live);

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertNull($result->exchangeOrderId);
        self::assertSame('live_not_supported_by_okx_dry_run', $result->metadata['reject_reason']);
    }

    public function testRejectsPlanForAnotherExchange(): void
    {
        $request = ExecutionRequest::forPlan($this->executablePlan(exchange: 'bitmart'), ExecutionMode::DryRun);

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertNull($result->exchangeOrderId);
        self::assertSame('wrong_exchange_for_okx_dry_run', $result->metadata['reject_reason']);
        self::assertSame('bitmart', $result->metadata['plan_exchange']);
    }

    public function testRejectsUnsupportedMarketType(): void
    {
        $request = ExecutionRequest::forPlan($this->executablePlan(marketType: 'spot'), ExecutionMode::DryRun);

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertNull($result->exchangeOrderId);
        self::assertSame('market_type_not_supported_by_okx_dry_run', $result->metadata['reject_reason']);
        self::assertSame('spot', $result->metadata['plan_market_type']);
    }

    public function testRejectsNonExecutablePlanWithoutExecuting(): void
    {
        $request = ExecutionRequest::forPlan($this->invalidPlan(), ExecutionMode::DryRun);

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertNull($result->exchangeOrderId);
        self::assertSame('order_plan_not_executable', $result->metadata['reject_reason']);
        self::assertContains('protection_plan_missing', $result->metadata['invalid_reasons']);
    }

    public function testDryRunOrderIdIsDeterministicAndPortIsStateless(): void
    {
        $port = new OkxDryRunExecutionPort();
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $first = $port->execute($request);
        $second = $port->execute($request);

        self::assertSame('OKX-DRYRUN-CID-OKX-1', $first->exchangeOrderId);
        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        self::assertEquals($first, $second);
    }

    public function testPreservesClientOrderIdAndIdempotencyKey(): void
    {
        $request = ExecutionRequest::forPlan($this->executablePlan(), ExecutionMode::DryRun);

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame('CID-OKX-1', $result->clientOrderId);
        self::assertSame('CID-OKX-1', $result->metadata['client_order_id']);
        self::assertSame('decision:BTCUSDT:long', $result->metadata['idempotency_key']);
    }

    public function testPreservesIncomingRequestMetadata(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            ['run_id' => 'run-77', 'correlation_id' => 'corr-9'],
        );

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame('run-77', $result->metadata['run_id']);
        self::assertSame('corr-9', $result->metadata['correlation_id']);
    }

    public function testCallerCannotOverrideGatewayAuthoritativeMetadata(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            ['gateway' => 'spoofed', 'simulated' => false, 'no_http' => false, 'no_private_post' => false],
        );

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame('okx', $result->metadata['gateway']);
        self::assertTrue($result->metadata['simulated']);
        self::assertTrue($result->metadata['no_http']);
        self::assertTrue($result->metadata['no_private_post']);
    }

    public function testCallerCannotSpoofRejectReasonOnLivePath(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::Live,
            ['reject_reason' => 'caller_spoofed'],
        );

        $result = (new OkxDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame('live_not_supported_by_okx_dry_run', $result->metadata['reject_reason']);
    }

    // --- fixtures ---

    private function executablePlan(
        string $exchange = 'okx',
        string $marketType = 'perpetual',
        string $instrument = 'BTCUSDT',
        string $clientOrderId = 'CID-OKX-1',
    ): OrderPlan
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
            clientOrderId: $clientOrderId,
            idempotencyKey: 'decision:BTCUSDT:long',
            instrument: $instrument,
        );

        return $plan->withValidation((new OrderPlanValidator())->validate($plan));
    }

    private function invalidPlan(): OrderPlan
    {
        return new OrderPlan(
            symbol: 'BTCUSDT',
            profile: 'scalper_micro',
            exchange: 'okx',
            marketType: 'perpetual',
            side: 'long',
            orderType: 'limit',
            marginMode: 'isolated',
            timeInForce: 'gtc',
            entryPrice: 100.0,
            quantity: 12.0,
            leverage: 5,
            protectionPlan: null,
            clientOrderId: 'CID-OKX-1',
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
