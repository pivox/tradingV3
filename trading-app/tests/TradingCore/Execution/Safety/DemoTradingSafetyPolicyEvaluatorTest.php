<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Execution\Safety;

use App\TradingCore\Execution\Safety\DemoTradingSafetyDecision;
use App\TradingCore\Execution\Safety\DemoTradingSafetyLevel;
use App\TradingCore\Execution\Safety\DemoTradingSafetyPolicy;
use App\TradingCore\Execution\Safety\DemoTradingSafetyPolicyEvaluator;
use App\TradingCore\Execution\Safety\ExchangeRuntimeEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DemoTradingSafetyDecision::class)]
#[CoversClass(DemoTradingSafetyLevel::class)]
#[CoversClass(DemoTradingSafetyPolicy::class)]
#[CoversClass(DemoTradingSafetyPolicyEvaluator::class)]
#[CoversClass(ExchangeRuntimeEnvironment::class)]
final class DemoTradingSafetyPolicyEvaluatorTest extends TestCase
{
    public function testMainnetWriteIsAlwaysBlockedEvenWhenFlagsAreEnabled(): void
    {
        $decision = (new DemoTradingSafetyPolicyEvaluator())->evaluate(
            $this->writePolicy(
                environment: ExchangeRuntimeEnvironment::MAINNET,
                mainnetWriteEnabled: true,
                demoTestnetWriteEnabled: true,
                killSwitchEnabled: false,
            ),
        );

        self::assertFalse($decision->allowed);
        self::assertSame(DemoTradingSafetyLevel::Blocked, $decision->level);
        self::assertContains('mainnet_write_forbidden', $decision->blockingErrors);
        self::assertContains('mainnet_write_enabled_must_remain_false', $decision->blockingErrors);
    }

    public function testMainnetWriteFlagIsBlockedEvenInDryRun(): void
    {
        $decision = (new DemoTradingSafetyPolicyEvaluator())->evaluate(
            new DemoTradingSafetyPolicy(
                environment: ExchangeRuntimeEnvironment::MAINNET,
                mainnetWriteEnabled: true,
            ),
        );

        self::assertFalse($decision->allowed);
        self::assertSame(DemoTradingSafetyLevel::Blocked, $decision->level);
        self::assertSame(['mainnet_write_enabled_must_remain_false'], $decision->blockingErrors);
    }

    public function testDemoWriteIsBlockedWhenKillSwitchIsActive(): void
    {
        $decision = (new DemoTradingSafetyPolicyEvaluator())->evaluate(
            $this->writePolicy(killSwitchEnabled: true),
        );

        self::assertFalse($decision->allowed);
        self::assertSame(DemoTradingSafetyLevel::Blocked, $decision->level);
        self::assertContains('kill_switch_enabled', $decision->blockingErrors);
    }

    public function testDemoWriteRequiresSymbolOrMarketWhitelist(): void
    {
        $decision = (new DemoTradingSafetyPolicyEvaluator())->evaluate(
            $this->writePolicy(
                allowedSymbols: [],
                allowedMarkets: [],
                killSwitchEnabled: false,
            ),
        );

        self::assertFalse($decision->allowed);
        self::assertContains('allowed_symbols_or_markets_required', $decision->blockingErrors);
    }

    public function testDemoWriteRequiresMaxNotional(): void
    {
        $decision = (new DemoTradingSafetyPolicyEvaluator())->evaluate(
            $this->writePolicy(maxNotional: null, killSwitchEnabled: false),
        );

        self::assertFalse($decision->allowed);
        self::assertContains('max_notional_required', $decision->blockingErrors);
    }

    public function testMaxNotionalMustBeFinite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxNotional must be positive and finite when provided.');

        new DemoTradingSafetyPolicy(
            environment: ExchangeRuntimeEnvironment::DEMO,
            maxNotional: NAN,
        );
    }

    public function testMaxNotionalCannotBeInfinite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxNotional must be positive and finite when provided.');

        new DemoTradingSafetyPolicy(
            environment: ExchangeRuntimeEnvironment::DEMO,
            maxNotional: INF,
        );
    }

    public function testDemoWriteRequiresStopLoss(): void
    {
        $decision = (new DemoTradingSafetyPolicyEvaluator())->evaluate(
            $this->writePolicy(requireStopLoss: false, killSwitchEnabled: false),
        );

        self::assertFalse($decision->allowed);
        self::assertContains('stop_loss_required', $decision->blockingErrors);
    }

    public function testDemoWriteRequiresExplicitDemoTestnetEnablement(): void
    {
        $decision = (new DemoTradingSafetyPolicyEvaluator())->evaluate(
            $this->writePolicy(demoTestnetWriteEnabled: false, killSwitchEnabled: false),
        );

        self::assertFalse($decision->allowed);
        self::assertContains('demo_testnet_write_not_enabled', $decision->blockingErrors);
    }

    public function testLocalDryRunIsAllowedWithoutWritePrerequisites(): void
    {
        $decision = (new DemoTradingSafetyPolicyEvaluator())->evaluate(
            new DemoTradingSafetyPolicy(
                environment: ExchangeRuntimeEnvironment::LOCAL_DRY_RUN,
            ),
        );

        self::assertTrue($decision->allowed);
        self::assertSame(DemoTradingSafetyLevel::LocalDryRun, $decision->level);
        self::assertSame([], $decision->blockingErrors);
    }

    public function testTestnetDryRunIsADemoTestnetCandidateWithoutExchangeOrder(): void
    {
        $decision = (new DemoTradingSafetyPolicyEvaluator())->evaluate(
            new DemoTradingSafetyPolicy(
                environment: ExchangeRuntimeEnvironment::TESTNET,
            ),
        );

        self::assertTrue($decision->allowed);
        self::assertSame(DemoTradingSafetyLevel::DemoTestnetCandidate, $decision->level);
        self::assertSame(['dry_run_no_exchange_order'], $decision->warnings);
    }

    public function testDemoWriteCanReachEnabledLevelWhenAllGuardsPass(): void
    {
        $decision = (new DemoTradingSafetyPolicyEvaluator())->evaluate(
            $this->writePolicy(killSwitchEnabled: false),
        );

        self::assertTrue($decision->allowed);
        self::assertSame(DemoTradingSafetyLevel::DemoTestnetEnabled, $decision->level);
        self::assertSame([], $decision->blockingErrors);
    }

    public function testSensitiveAuditFieldsAreRedacted(): void
    {
        $policy = $this->writePolicy(
            killSwitchEnabled: false,
            auditContext: [
                'OKX_DEMO_API_KEY' => 'demo-key',
                'passphrase' => 'demo-passphrase',
                'Authorization' => 'Bearer demo-token',
                'Cookie' => 'session=demo-cookie',
                'OK-ACCESS-SIGN' => 'okx-signature',
                'X-BM-SIGN' => 'bitmart-signature',
                'nested' => [
                    'private_key' => 'wallet-secret',
                    'apiKey' => 'camel-api-key',
                    'privateKey' => 'camel-private-key',
                    'safe_value' => 'visible',
                ],
            ],
        );

        $decision = (new DemoTradingSafetyPolicyEvaluator())->evaluate($policy);
        $redacted = $decision->toRedactedArray();

        self::assertSame('[redacted]', $redacted['policy']['audit_context']['OKX_DEMO_API_KEY']);
        self::assertSame('[redacted]', $redacted['policy']['audit_context']['passphrase']);
        self::assertSame('[redacted]', $redacted['policy']['audit_context']['Authorization']);
        self::assertSame('[redacted]', $redacted['policy']['audit_context']['Cookie']);
        self::assertSame('[redacted]', $redacted['policy']['audit_context']['OK-ACCESS-SIGN']);
        self::assertSame('[redacted]', $redacted['policy']['audit_context']['X-BM-SIGN']);
        self::assertSame('[redacted]', $redacted['policy']['audit_context']['nested']['private_key']);
        self::assertSame('[redacted]', $redacted['policy']['audit_context']['nested']['apiKey']);
        self::assertSame('[redacted]', $redacted['policy']['audit_context']['nested']['privateKey']);
        self::assertSame('visible', $redacted['policy']['audit_context']['nested']['safe_value']);

        $encoded = json_encode($redacted, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('demo-key', $encoded);
        self::assertStringNotContainsString('wallet-secret', $encoded);
        self::assertStringNotContainsString('camel-api-key', $encoded);
        self::assertStringNotContainsString('camel-private-key', $encoded);
        self::assertStringNotContainsString('Bearer demo-token', $encoded);
        self::assertStringNotContainsString('session=demo-cookie', $encoded);
        self::assertStringNotContainsString('okx-signature', $encoded);
        self::assertStringNotContainsString('bitmart-signature', $encoded);
    }

    /**
     * @param list<string> $allowedSymbols
     * @param list<string> $allowedMarkets
     * @param array<string,mixed> $auditContext
     */
    private function writePolicy(
        ExchangeRuntimeEnvironment $environment = ExchangeRuntimeEnvironment::DEMO,
        bool $mainnetWriteEnabled = false,
        bool $demoTestnetWriteEnabled = true,
        bool $killSwitchEnabled = true,
        bool $requireStopLoss = true,
        array $allowedSymbols = ['BTCUSDT'],
        array $allowedMarkets = [],
        ?float $maxNotional = 25.0,
        array $auditContext = [],
    ): DemoTradingSafetyPolicy {
        return new DemoTradingSafetyPolicy(
            environment: $environment,
            dryRun: false,
            mainnetWriteEnabled: $mainnetWriteEnabled,
            demoTestnetWriteEnabled: $demoTestnetWriteEnabled,
            killSwitchEnabled: $killSwitchEnabled,
            requireStopLoss: $requireStopLoss,
            allowedSymbols: $allowedSymbols,
            allowedMarkets: $allowedMarkets,
            maxNotional: $maxNotional,
            auditContext: $auditContext,
        );
    }
}
