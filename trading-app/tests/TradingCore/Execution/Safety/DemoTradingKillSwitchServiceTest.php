<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Execution\Safety;

use App\Common\Enum\Exchange;
use App\TradingCore\Execution\Safety\DemoTradingAuditSinkInterface;
use App\TradingCore\Execution\Safety\DemoTradingKillSwitchDecision;
use App\TradingCore\Execution\Safety\DemoTradingKillSwitchService;
use App\TradingCore\Execution\Safety\DemoTradingMutationAttempt;
use App\TradingCore\Execution\Safety\DemoTradingSafetyDecision;
use App\TradingCore\Execution\Safety\DemoTradingSafetyLevel;
use App\TradingCore\Execution\Safety\DemoTradingSafetyPolicyEvaluator;
use App\TradingCore\Execution\Safety\ExchangeRuntimeEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DemoTradingKillSwitchDecision::class)]
#[CoversClass(DemoTradingKillSwitchService::class)]
#[CoversClass(DemoTradingMutationAttempt::class)]
#[CoversClass(DemoTradingSafetyDecision::class)]
#[CoversClass(DemoTradingSafetyPolicyEvaluator::class)]
final class DemoTradingKillSwitchServiceTest extends TestCase
{
    public function testGlobalKillSwitchBlocksEveryDemoTestnetAttempt(): void
    {
        $sink = new CapturingDemoTradingAuditSink();
        $decision = $this->service(
            sink: $sink,
            globalDemoTradingEnabled: false,
            okxDemoTradingEnabled: true,
            hyperliquidTestnetTradingEnabled: true,
        )->evaluate($this->okxAttempt());

        self::assertFalse($decision->allowed);
        self::assertContains('demo_trading_disabled', $decision->reasons);
        self::assertContains('kill_switch_enabled', $decision->reasons);
        self::assertSame('blocked', $sink->events[0]['outcome']);
        self::assertSame(['demo_trading_disabled', 'kill_switch_enabled'], $sink->events[0]['reasons']);
    }

    public function testExchangeKillSwitchBlocksOnlyConcernedExchange(): void
    {
        $sink = new CapturingDemoTradingAuditSink();
        $service = $this->service(
            sink: $sink,
            globalDemoTradingEnabled: true,
            okxDemoTradingEnabled: false,
            hyperliquidTestnetTradingEnabled: true,
        );

        $okxDecision = $service->evaluate($this->okxAttempt());
        $hyperliquidDecision = $service->evaluate($this->hyperliquidAttempt());

        self::assertFalse($okxDecision->allowed);
        self::assertContains('okx_demo_trading_disabled', $okxDecision->reasons);

        self::assertTrue($hyperliquidDecision->allowed);
        self::assertSame(DemoTradingSafetyLevel::DemoTestnetEnabled, $hyperliquidDecision->safetyDecision->level);
        self::assertSame('blocked', $sink->events[0]['outcome']);
        self::assertSame('allowed', $sink->events[1]['outcome']);
    }

    public function testAuditEntryIsStandardizedAndRedacted(): void
    {
        $sink = new CapturingDemoTradingAuditSink();
        $decision = $this->service($sink)->evaluate($this->okxAttempt(
            auditContext: [
                'OKX_DEMO_API_KEY' => 'demo-key',
                'OK-ACCESS-SIGN' => 'raw-signature',
                'signal_id' => 'signal-789',
                'nested' => [
                    'private_key' => 'wallet-secret',
                    'safe_value' => 'visible',
                ],
            ],
            correlationIds: [
                'orchestration_run_id' => 'run-123',
                'correlation_run_id' => 'corr-456',
                'signal_run_id' => 'signal-run-123',
            ],
        ));

        self::assertTrue($decision->allowed);
        self::assertCount(1, $sink->events);

        $event = $sink->events[0];
        self::assertSame('okx', $event['exchange']);
        self::assertSame('demo', $event['environment']);
        self::assertSame('scalper_micro', $event['mode']);
        self::assertSame('scalper_micro', $event['profile']);
        self::assertSame('BTCUSDT', $event['symbol']);
        self::assertSame('perpetual', $event['market']);
        self::assertSame(12.5, $event['notional']);
        self::assertSame('cid-001', $event['client_order_id']);
        self::assertSame('place_order', $event['action']);
        self::assertSame('allowed', $event['outcome']);
        self::assertTrue($event['allowed']);
        self::assertSame([], $event['reasons']);
        self::assertSame('run-123', $event['correlation_ids']['orchestration_run_id']);
        self::assertSame('signal-run-123', $event['correlation_ids']['signal_run_id']);
        self::assertSame('[redacted]', $event['audit_context']['OKX_DEMO_API_KEY']);
        self::assertSame('[redacted]', $event['audit_context']['OK-ACCESS-SIGN']);
        self::assertSame('signal-789', $event['audit_context']['signal_id']);
        self::assertSame('[redacted]', $event['audit_context']['nested']['private_key']);
        self::assertSame('visible', $event['audit_context']['nested']['safe_value']);

        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('demo-key', $encoded);
        self::assertStringNotContainsString('raw-signature', $encoded);
        self::assertStringNotContainsString('wallet-secret', $encoded);
    }

    public function testMainnetRemainsBlockedEvenWhenEverySwitchIsOff(): void
    {
        $sink = new CapturingDemoTradingAuditSink();
        $decision = $this->service(
            sink: $sink,
            globalDemoTradingEnabled: true,
            okxDemoTradingEnabled: true,
            hyperliquidTestnetTradingEnabled: true,
        )->evaluate($this->okxAttempt(
            environment: ExchangeRuntimeEnvironment::MAINNET,
            effectiveKillSwitchEnabled: false,
            mainnetWriteEnabled: false,
        ));

        self::assertFalse($decision->allowed);
        self::assertContains('mainnet_write_forbidden', $decision->reasons);
        self::assertSame('blocked', $sink->events[0]['outcome']);
    }

    public function testUnsupportedDemoTestnetPairsAreBlocked(): void
    {
        $sink = new CapturingDemoTradingAuditSink();
        $service = $this->service(
            sink: $sink,
            globalDemoTradingEnabled: true,
            okxDemoTradingEnabled: true,
            hyperliquidTestnetTradingEnabled: true,
        );

        $bitmartDemo = $service->evaluate($this->attempt(
            exchange: Exchange::BITMART,
            environment: ExchangeRuntimeEnvironment::DEMO,
            symbol: 'BTCUSDT',
            clientOrderId: 'bitmart-cid-001',
        ));
        $okxTestnet = $service->evaluate($this->attempt(
            exchange: Exchange::OKX,
            environment: ExchangeRuntimeEnvironment::TESTNET,
            symbol: 'BTCUSDT',
            clientOrderId: 'okx-testnet-cid-001',
        ));

        self::assertFalse($bitmartDemo->allowed);
        self::assertContains('exchange_environment_pair_unsupported', $bitmartDemo->reasons);
        self::assertFalse($okxTestnet->allowed);
        self::assertContains('exchange_environment_pair_unsupported', $okxTestnet->reasons);
        self::assertSame('blocked', $sink->events[0]['outcome']);
        self::assertSame('blocked', $sink->events[1]['outcome']);
    }

    public function testClientOrderIdIsRequiredBeforeAllowingMutation(): void
    {
        $sink = new CapturingDemoTradingAuditSink();
        $decision = $this->service($sink)->evaluate($this->attempt(
            exchange: Exchange::OKX,
            environment: ExchangeRuntimeEnvironment::DEMO,
            symbol: 'BTCUSDT',
            clientOrderId: '',
        ));

        self::assertFalse($decision->allowed);
        self::assertContains('client_order_id_required', $decision->reasons);
        self::assertSame('blocked', $sink->events[0]['outcome']);
        self::assertNull($sink->events[0]['client_order_id']);
    }

    public function testAllowedMutationAttemptIsAuditedBeforeCallerCanProceed(): void
    {
        $sink = new CapturingDemoTradingAuditSink();
        $decision = $this->service($sink)->evaluate($this->okxAttempt());

        self::assertTrue($decision->allowed);
        self::assertCount(1, $sink->events);
        self::assertSame('allowed', $sink->events[0]['outcome']);
        self::assertSame($sink->events[0], $decision->auditEvent);
    }

    public function testAuditFailureBlocksMutation(): void
    {
        $decision = $this->service(new FailingDemoTradingAuditSink())->evaluate($this->okxAttempt());

        self::assertFalse($decision->allowed);
        self::assertContains('audit_failed', $decision->reasons);
        self::assertSame('blocked', $decision->auditEvent['outcome']);
    }

    /**
     * @param array<string,mixed> $auditContext
     * @param array<string,string> $correlationIds
     */
    private function okxAttempt(
        ExchangeRuntimeEnvironment $environment = ExchangeRuntimeEnvironment::DEMO,
        bool $effectiveKillSwitchEnabled = false,
        bool $mainnetWriteEnabled = false,
        array $auditContext = [],
        array $correlationIds = [],
    ): DemoTradingMutationAttempt {
        return new DemoTradingMutationAttempt(
            exchange: Exchange::OKX,
            environment: $environment,
            mode: 'scalper_micro',
            profile: 'scalper_micro',
            market: 'perpetual',
            symbol: 'BTCUSDT',
            notional: 12.5,
            clientOrderId: 'cid-001',
            action: 'place_order',
            mainnetWriteEnabled: $mainnetWriteEnabled,
            demoTestnetWriteEnabled: true,
            effectiveKillSwitchEnabled: $effectiveKillSwitchEnabled,
            requireStopLoss: true,
            stopLossPresent: true,
            allowedSymbols: ['BTCUSDT'],
            allowedMarkets: ['perpetual'],
            maxNotional: 25.0,
            correlationIds: $correlationIds,
            auditContext: $auditContext,
        );
    }

    private function hyperliquidAttempt(): DemoTradingMutationAttempt
    {
        return $this->attempt(
            exchange: Exchange::HYPERLIQUID,
            environment: ExchangeRuntimeEnvironment::TESTNET,
            symbol: 'BTC',
            clientOrderId: 'hl-cid-001',
            allowedSymbols: ['BTC'],
        );
    }

    /**
     * @param list<string> $allowedSymbols
     */
    private function attempt(
        Exchange $exchange,
        ExchangeRuntimeEnvironment $environment,
        string $symbol,
        string $clientOrderId,
        array $allowedSymbols = ['BTCUSDT'],
    ): DemoTradingMutationAttempt {
        return new DemoTradingMutationAttempt(
            exchange: $exchange,
            environment: $environment,
            mode: 'scalper_micro',
            profile: 'scalper_micro',
            market: 'perpetual',
            symbol: $symbol,
            notional: 12.5,
            clientOrderId: $clientOrderId,
            action: 'place_order',
            demoTestnetWriteEnabled: true,
            effectiveKillSwitchEnabled: false,
            requireStopLoss: true,
            stopLossPresent: true,
            allowedSymbols: $allowedSymbols,
            allowedMarkets: ['perpetual'],
            maxNotional: 25.0,
        );
    }

    private function service(
        DemoTradingAuditSinkInterface $sink,
        bool $globalDemoTradingEnabled = true,
        bool $okxDemoTradingEnabled = true,
        bool $hyperliquidTestnetTradingEnabled = true,
    ): DemoTradingKillSwitchService {
        return new DemoTradingKillSwitchService(
            evaluator: new DemoTradingSafetyPolicyEvaluator(),
            auditSink: $sink,
            globalDemoTradingEnabled: $globalDemoTradingEnabled,
            okxDemoTradingEnabled: $okxDemoTradingEnabled,
            hyperliquidTestnetTradingEnabled: $hyperliquidTestnetTradingEnabled,
        );
    }
}

final class CapturingDemoTradingAuditSink implements DemoTradingAuditSinkInterface
{
    /** @var list<array<string,mixed>> */
    public array $events = [];

    public function recordDemoTradingAttempt(array $event): void
    {
        $this->events[] = $event;
    }
}

final class FailingDemoTradingAuditSink implements DemoTradingAuditSinkInterface
{
    public function recordDemoTradingAttempt(array $event): void
    {
        throw new \RuntimeException('audit sink unavailable');
    }
}
