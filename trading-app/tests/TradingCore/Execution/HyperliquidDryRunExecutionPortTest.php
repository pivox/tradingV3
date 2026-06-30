<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\Common\Enum\Exchange;
use App\Exchange\Readiness\ExchangePrivateObservabilityStatus;
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

    public function testDryRunBuildsRedactedHyperliquidExchangeActionsWithFakeSigner(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(clientOrderId: 'CIDHL1'),
            ExecutionMode::DryRun,
            [
                'environment' => 'local_dry_run',
                'allowed_symbols' => ['BTCUSDT'],
                'max_notional' => 2000.0,
                'max_leverage' => 10,
                'hyperliquid_asset_id' => 0,
                'HYPERLIQUID_PRIVATE_KEY' => 'raw-private-key',
                'signature' => 'raw-signature',
            ],
            new \DateTimeImmutable('2026-06-30T01:15:00+00:00'),
        );

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::DryRun, $result->status);
        self::assertTrue($result->metadata['local_dry_run_ready']);
        self::assertSame('local_dry_run', $result->metadata['environment']);
        self::assertSame('local_dry_run_ready', $result->metadata['readiness_level']);

        $dryRun = $result->raw['hyperliquid_dry_run'];
        self::assertTrue($dryRun['no_http']);
        self::assertTrue($dryRun['no_exchange_call']);
        self::assertTrue($dryRun['no_broadcast']);
        self::assertTrue($dryRun['redacted']);
        self::assertSame('fake_hyperliquid_signer', $dryRun['signer']);
        self::assertSame('deterministic_preview', $dryRun['nonce_policy']);

        self::assertSame('set_leverage', $dryRun['requests'][0]['operation']);
        self::assertSame('/exchange', $dryRun['requests'][0]['path']);
        self::assertSame([
            'type' => 'updateLeverage',
            'asset' => 0,
            'isCross' => false,
            'leverage' => 5,
        ], $dryRun['requests'][0]['body']['action']);
        self::assertSame('fake_hyperliquid_signer', $dryRun['requests'][0]['body']['signature']['scheme']);

        self::assertSame('submit_order', $dryRun['requests'][1]['operation']);
        self::assertSame('order', $dryRun['requests'][1]['body']['action']['type']);
        self::assertSame(0, $dryRun['requests'][1]['body']['action']['orders'][0]['a']);
        self::assertTrue($dryRun['requests'][1]['body']['action']['orders'][0]['b']);
        self::assertSame('100', $dryRun['requests'][1]['body']['action']['orders'][0]['p']);
        self::assertSame('12', $dryRun['requests'][1]['body']['action']['orders'][0]['s']);
        self::assertFalse($dryRun['requests'][1]['body']['action']['orders'][0]['r']);

        self::assertSame('stop_loss', $dryRun['requests'][2]['operation']);
        self::assertTrue($dryRun['requests'][2]['body']['action']['orders'][0]['r']);
        self::assertSame('sl', $dryRun['requests'][2]['body']['action']['orders'][0]['t']['trigger']['tpsl']);
        self::assertSame('98', $dryRun['requests'][2]['body']['action']['orders'][0]['t']['trigger']['triggerPx']);

        self::assertSame('take_profit', $dryRun['requests'][3]['operation']);
        self::assertTrue($dryRun['requests'][3]['body']['action']['orders'][0]['r']);
        self::assertSame('tp', $dryRun['requests'][3]['body']['action']['orders'][0]['t']['trigger']['tpsl']);
        self::assertSame('103', $dryRun['requests'][3]['body']['action']['orders'][0]['t']['trigger']['triggerPx']);

        $encoded = json_encode([$result->metadata, $result->raw], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('raw-private-key', $encoded);
        self::assertStringNotContainsString('raw-signature', $encoded);
        self::assertStringContainsString('[redacted]', $encoded);
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

    public function testDryRunRejectsMainnetEnvironmentBeforePayloadSerialization(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            ['environment' => 'mainnet'],
        );

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame('mainnet_environment_forbidden_for_hyperliquid_dry_run', $result->metadata['reject_reason']);
        self::assertSame([], $result->raw);
    }

    public function testDryRunRejectsSymbolOutsideWhitelist(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            ['allowed_symbols' => ['ETHUSDT']],
        );

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

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

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

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

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame('leverage_cap_exceeded', $result->metadata['reject_reason']);
        self::assertSame(5, $result->metadata['leverage']);
        self::assertSame(4, $result->metadata['max_leverage']);
    }

    public function testDryRunRejectsNonBtcPlanWithoutExplicitAssetId(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(symbol: 'ETHUSDT', clientOrderId: 'CID-HL-ETH'),
            ExecutionMode::DryRun,
        );

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame('hyperliquid_asset_id_required_for_symbol', $result->metadata['reject_reason']);
        self::assertSame([], $result->raw);
    }

    public function testDryRunRejectsUnencodableHyperliquidPreviewPayload(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(quantity: 12.123456789),
            ExecutionMode::DryRun,
        );

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame('hyperliquid_dry_run_payload_unencodable', $result->metadata['reject_reason']);
        self::assertSame([], $result->raw);
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

    public function testRejectsPlanMissingStopLossWithoutSigningPayloads(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(protectionPlan: $this->protectionPlanWithoutStopLoss()),
            ExecutionMode::DryRun,
        );

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::Rejected, $result->status);
        self::assertSame('order_plan_not_executable', $result->metadata['reject_reason']);
        self::assertContains('stop_loss_missing', $result->metadata['invalid_reasons']);
        self::assertSame([], $result->raw);
    }

    public function testDryRunKeepsPrivateObservabilityInformativeOnly(): void
    {
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            [
                'environment' => 'local_dry_run',
                'private_observability_status' => ExchangePrivateObservabilityStatus::absent(Exchange::HYPERLIQUID, 'local_dry_run'),
            ],
        );

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame(ExecutionStatus::DryRun, $result->status);
        self::assertTrue($result->metadata['private_observability_decision']['allowed']);
        self::assertIsArray($result->metadata['private_observability_status']);
        self::assertContains(
            'private_observability_absent_for_dry_run',
            $result->metadata['private_observability_decision']['warnings'],
        );
    }

    public function testDryRunRedactsPrivateObservabilityStatusInsideSafetyAuditContext(): void
    {
        $status = new ExchangePrivateObservabilityStatus(
            exchange: Exchange::HYPERLIQUID,
            environment: 'local_dry_run',
            privateWsSupported: true,
            privateWsConnected: true,
            privateWsAuthenticated: true,
            ordersStreamReady: true,
            fillsStreamReady: true,
            positionsStreamReady: true,
            initialSnapshotLoaded: true,
            blockingErrors: ['secret=super-sensitive-value'],
            warnings: ['api_key=super-sensitive-value'],
        );
        $request = ExecutionRequest::forPlan(
            $this->executablePlan(),
            ExecutionMode::DryRun,
            [
                'environment' => 'local_dry_run',
                'private_observability_status' => $status,
            ],
        );

        $result = (new HyperliquidDryRunExecutionPort())->execute($request);

        self::assertSame(
            ['[redacted]'],
            $result->metadata['safety_decision']['policy']['audit_context']['private_observability_status']['blocking_errors'],
        );
        self::assertSame(
            ['[redacted]'],
            $result->metadata['safety_decision']['policy']['audit_context']['private_observability_status']['warnings'],
        );

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('super-sensitive-value', $encoded);
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

    private function executablePlan(
        string $symbol = 'BTCUSDT',
        string $exchange = 'hyperliquid',
        string $marketType = 'perpetual',
        ?ProtectionPlan $protectionPlan = null,
        string $clientOrderId = 'CID-HL-1',
        float $quantity = 12.0,
    ): OrderPlan {
        $protectionPlan ??= $this->protectionPlan();

        $plan = new OrderPlan(
            symbol: $symbol,
            profile: 'scalper_micro',
            exchange: $exchange,
            marketType: $marketType,
            side: 'long',
            orderType: 'limit',
            marginMode: 'isolated',
            timeInForce: 'gtc',
            entryPrice: 100.0,
            quantity: $quantity,
            leverage: 5,
            protectionPlan: $protectionPlan,
            clientOrderId: $clientOrderId,
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

    private function protectionPlanWithoutStopLoss(): ProtectionPlan
    {
        return new ProtectionPlan(
            stopLoss: null,
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
            isValid: false,
            status: ProtectionPlanStatus::Invalid,
        );
    }
}
