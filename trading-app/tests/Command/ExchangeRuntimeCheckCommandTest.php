<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ExchangeRuntimeCheckCommand;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\Dto\AccountDto;
use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\ExchangeProviderRegistryInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\SystemProviderInterface;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Contract\ExchangeAdapterRegistryInterface;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Okx\OkxConfig;
use App\Provider\Context\ExchangeContext;
use App\Provider\Registry\ExchangeProviderBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ExchangeRuntimeCheckCommand::class)]
final class ExchangeRuntimeCheckCommandTest extends TestCase
{
    public function testReportsUnreadyOkxRuntimeWithoutProviderOrCredentials(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(Exchange::OKX, MarketType::PERPETUAL)),
            $this->missingProviderRegistry(),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Exchange: okx', $output);
        self::assertStringContainsString('Market type: perpetual', $output);
        self::assertStringContainsString('Adapter: found', $output);
        self::assertStringContainsString('Provider bundle: missing', $output);
        self::assertStringContainsString('Credentials: missing', $output);
        self::assertStringContainsString('REST: unknown', $output);
        self::assertStringContainsString('Private WS: unsupported', $output);
        self::assertStringContainsString('Live trading: disabled', $output);
        self::assertStringContainsString('Dry-run only: yes', $output);
        self::assertStringContainsString('Live allowed: no', $output);
        self::assertStringContainsString('Demo trading enabled: no', $output);
        self::assertStringContainsString('Recommended dry_run: true', $output);
        self::assertStringContainsString('Schedule ready: no', $output);
    }

    public function testOkxSkeletonStaysUnscheduledEvenWithCredentialsProviderAndDemoFlag(): void
    {
        // OKX-002 exposes an explicit provider bundle, but the provider read paths are still
        // skeleton-only. Presence in the registry must not make the schedule preflight green.
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(Exchange::OKX, MarketType::PERPETUAL, supportsPrivateWs: true)),
            $this->providerRegistry($this->providerBundle(Exchange::OKX, MarketType::PERPETUAL)),
            new OkxConfig(
                environment: 'demo',
                apiKey: 'key',
                apiSecret: 'secret',
                apiPassphrase: 'pass',
                demoTradingEnabled: true,
                liveEnabled: true,
            ),
            new HyperliquidConfig(),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Exchange: okx', $output);
        self::assertStringContainsString('Adapter: found', $output);
        self::assertStringContainsString('Provider bundle: found', $output);
        self::assertStringContainsString('Credentials: ok', $output);
        // Even with OKX_LIVE_ENABLED=1 and demo trading on, live stays forbidden in PR11.
        self::assertStringContainsString('Live trading: disabled', $output);
        self::assertStringContainsString('Dry-run only: yes', $output);
        self::assertStringContainsString('Live allowed: no', $output);
        self::assertStringContainsString('Demo trading enabled: yes', $output);
        self::assertStringContainsString('Recommended dry_run: true', $output);
        self::assertStringContainsString('Schedule ready: no', $output);
        // The Hyperliquid-specific lines must never leak into OKX output.
        self::assertStringNotContainsString('Network:', $output);
        self::assertStringNotContainsString('Mainnet enabled:', $output);
    }

    public function testOkxRuntimeCheckSurfacesLocalDryRunReadinessReasons(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(
                Exchange::OKX,
                MarketType::PERPETUAL,
                supportsPrivateWs: true,
                supportsTriggerOrders: true,
            )),
            $this->providerRegistry($this->providerBundle(Exchange::OKX, MarketType::PERPETUAL)),
            new OkxConfig(
                environment: 'demo',
                apiKey: 'key',
                apiSecret: 'secret',
                apiPassphrase: 'pass',
                simulatedTrading: true,
                demoTradingEnabled: false,
                liveEnabled: false,
            ),
            new HyperliquidConfig(),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Readiness level: local_dry_run_ready', $output);
        self::assertStringContainsString('Readiness blocking errors: none', $output);
        self::assertStringContainsString('Readiness warnings: private_observability_absent_for_dry_run, demo_testnet_write_not_enabled', $output);
        self::assertStringContainsString('Mainnet write guard: yes', $output);
        self::assertStringContainsString('Demo/testnet write guard: yes', $output);
        self::assertStringContainsString('Stop loss capability: yes', $output);
        self::assertStringContainsString('Kill switch: enabled', $output);
        self::assertStringContainsString('Schedule ready: yes', $output);
    }

    public function testOkxRuntimeCheckDoesNotScheduleBeforeLocalDryRunReadiness(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(
                Exchange::OKX,
                MarketType::PERPETUAL,
                supportsPrivateWs: true,
                supportsTriggerOrders: true,
            )),
            $this->providerRegistry($this->providerBundle(Exchange::OKX, MarketType::PERPETUAL)),
            new OkxConfig(
                environment: 'demo',
                simulatedTrading: true,
                demoTradingEnabled: true,
                liveEnabled: false,
            ),
            new HyperliquidConfig(),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Credentials: missing', $output);
        self::assertStringContainsString('Readiness level: public_read_only', $output);
        self::assertStringContainsString('Readiness warnings: private_read_not_ready', $output);
        self::assertStringContainsString('Schedule ready: no', $output);
    }

    public function testOkxRuntimeCheckReportsDemoTestnetCandidateWhenDemoTradingIsExplicitlyEnabled(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(
                Exchange::OKX,
                MarketType::PERPETUAL,
                supportsPrivateWs: true,
                supportsTriggerOrders: true,
            )),
            $this->providerRegistry($this->providerBundle(Exchange::OKX, MarketType::PERPETUAL)),
            new OkxConfig(
                environment: 'demo',
                apiKey: 'key',
                apiSecret: 'secret',
                apiPassphrase: 'pass',
                simulatedTrading: true,
                demoTradingEnabled: true,
                liveEnabled: false,
            ),
            new HyperliquidConfig(),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Readiness level: demo_testnet_candidate', $output);
        self::assertStringContainsString('Readiness blocking errors: none', $output);
        self::assertStringContainsString('Readiness warnings: private_observability_absent_for_dry_run', $output);
        self::assertStringNotContainsString('Readiness level: demo_testnet_enabled', $output);
        self::assertStringContainsString('Live allowed: no', $output);
        self::assertStringContainsString('Recommended dry_run: true', $output);
    }

    public function testOkxRuntimeCheckDoesNotMarkPublicReadReadyWhenProviderReturnsNoContracts(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(
                Exchange::OKX,
                MarketType::PERPETUAL,
                supportsPrivateWs: true,
                supportsTriggerOrders: true,
            )),
            $this->providerRegistry($this->providerBundle(
                Exchange::OKX,
                MarketType::PERPETUAL,
                contractsLoaded: false,
            )),
            new OkxConfig(
                environment: 'demo',
                apiKey: 'key',
                apiSecret: 'secret',
                apiPassphrase: 'pass',
                simulatedTrading: true,
                demoTradingEnabled: true,
                liveEnabled: false,
            ),
            new HyperliquidConfig(),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Readiness level: not_ready', $output);
        self::assertStringContainsString('Readiness blocking errors: instruments_not_loaded, metadata_invalid, precision_invalid', $output);
        self::assertStringContainsString('Schedule ready: no', $output);
    }

    public function testOkxRuntimeCheckDoesNotMarkPrivateReadReadyWhenAccountProbeFails(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(
                Exchange::OKX,
                MarketType::PERPETUAL,
                supportsPrivateWs: true,
                supportsTriggerOrders: true,
            )),
            $this->providerRegistry($this->providerBundle(
                Exchange::OKX,
                MarketType::PERPETUAL,
                accountReadable: false,
            )),
            new OkxConfig(
                environment: 'demo',
                apiKey: 'key',
                apiSecret: 'secret',
                apiPassphrase: 'pass',
                simulatedTrading: true,
                demoTradingEnabled: true,
                liveEnabled: false,
            ),
            new HyperliquidConfig(),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Readiness level: public_read_only', $output);
        self::assertStringContainsString('Readiness warnings: okx_private_read_probe_failed, private_read_not_ready', $output);
        self::assertStringContainsString('Schedule ready: no', $output);
    }

    public function testReportsUnreadyHyperliquidRuntimeWithoutProviderOrCredentials(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(Exchange::HYPERLIQUID, MarketType::PERPETUAL)),
            $this->missingProviderRegistry(),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(environment: 'testnet'),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'hyperliquid',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Exchange: hyperliquid', $output);
        self::assertStringContainsString('Adapter: found', $output);
        self::assertStringContainsString('Provider bundle: missing', $output);
        self::assertStringContainsString('Credentials: missing', $output);
        self::assertStringContainsString('Live trading: disabled', $output);
        self::assertStringContainsString('Dry-run only: yes', $output);
        self::assertStringContainsString('Live allowed: no', $output);
        self::assertStringContainsString('Network: testnet', $output);
        self::assertStringContainsString('Mainnet enabled: no', $output);
        self::assertStringContainsString('Recommended dry_run: true', $output);
        self::assertStringContainsString('Schedule ready: no', $output);
        // The OKX-specific demo line must never leak into Hyperliquid output.
        self::assertStringNotContainsString('Demo trading enabled:', $output);
    }

    public function testHyperliquidStaysDryRunOnlyEvenWithCredentialsProviderAndTestnet(): void
    {
        // HL-002 exposes an explicit provider bundle, but every Hyperliquid runtime path
        // remains skeleton-only. Presence in the registry must not make the schedule preflight green.
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(Exchange::HYPERLIQUID, MarketType::PERPETUAL, supportsPrivateWs: true)),
            $this->providerRegistry($this->providerBundle(Exchange::HYPERLIQUID, MarketType::PERPETUAL)),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(
                environment: 'testnet',
                network: 'testnet',
                testnetAgentPrivateKey: 'fixture-agent-material',
                testnetAgentAddress: '0xagent',
                testnetAccountAddress: '0xabc',
            ),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'hyperliquid',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Exchange: hyperliquid', $output);
        self::assertStringContainsString('Adapter: found', $output);
        self::assertStringContainsString('Provider bundle: found', $output);
        self::assertStringContainsString('Credentials: ok', $output);
        self::assertStringContainsString('Live trading: disabled', $output);
        self::assertStringContainsString('Dry-run only: yes', $output);
        self::assertStringContainsString('Live allowed: no', $output);
        self::assertStringContainsString('Network: testnet', $output);
        self::assertStringContainsString('Mainnet enabled: no', $output);
        self::assertStringContainsString('Readiness level: not_ready', $output);
        self::assertStringContainsString('hyperliquid_provider_bundle_skeleton_not_ready', $output);
        self::assertStringContainsString('Recommended dry_run: true', $output);
        self::assertStringContainsString('Schedule ready: no', $output);
    }

    public function testHyperliquidMainnetEnabledStillForbidsLive(): void
    {
        // HYPERLIQUID_MAINNET_ENABLED=1 on mainnet is a network capability, NOT a live-trading
        // authorization: live stays disabled and dry-run stays recommended in PR12.
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(Exchange::HYPERLIQUID, MarketType::PERPETUAL, supportsPrivateWs: true)),
            $this->providerRegistry($this->providerBundle(Exchange::HYPERLIQUID, MarketType::PERPETUAL)),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(
                environment: 'mainnet',
                mainnetEnabled: true,
                network: 'mainnet',
                testnetAgentPrivateKey: 'fixture-agent-material',
                testnetAgentAddress: '0xagent',
                testnetAccountAddress: '0xabc',
            ),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'hyperliquid',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Credentials: ok', $output);
        self::assertStringContainsString('Live trading: disabled', $output);
        self::assertStringContainsString('Dry-run only: yes', $output);
        self::assertStringContainsString('Live allowed: no', $output);
        self::assertStringContainsString('Network: mainnet', $output);
        self::assertStringContainsString('Mainnet enabled: yes', $output);
        self::assertStringContainsString('Readiness level: not_ready', $output);
        self::assertStringContainsString('Readiness blocking errors: mainnet_write_guard_missing', $output);
        self::assertStringContainsString('hyperliquid_provider_bundle_skeleton_not_ready', $output);
        self::assertStringContainsString('Mainnet write guard: no', $output);
        self::assertStringContainsString('Demo/testnet write guard: no', $output);
        self::assertStringContainsString('Recommended dry_run: true', $output);
    }

    public function testReportsReadyBitmartRuntimeWhenAdapterAndProviderExist(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(Exchange::BITMART, MarketType::PERPETUAL)),
            $this->providerRegistry($this->providerBundle(Exchange::BITMART, MarketType::PERPETUAL)),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(),
            ['BITMART_API_KEY' => 'key', 'BITMART_SECRET_KEY' => 'secret', 'BITMART_API_MEMO' => 'memo'],
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Exchange: bitmart', $output);
        self::assertStringContainsString('Market type: perpetual', $output);
        self::assertStringContainsString('Adapter: found', $output);
        self::assertStringContainsString('Provider bundle: found', $output);
        self::assertStringContainsString('Credentials: ok', $output);
        self::assertStringContainsString('REST: unknown', $output);
        self::assertStringContainsString('Recommended dry_run: false', $output);
        self::assertStringContainsString('Schedule ready: yes', $output);
        // The OKX/Hyperliquid dry-run-only gates must not leak into Bitmart legacy output.
        self::assertStringNotContainsString('Dry-run only:', $output);
        self::assertStringNotContainsString('Live allowed:', $output);
        self::assertStringNotContainsString('Network:', $output);
        self::assertStringNotContainsString('Mainnet enabled:', $output);
    }

    public function testReportsUnreadyBitmartRuntimeWhenCredentialsAreMissing(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(Exchange::BITMART, MarketType::PERPETUAL)),
            $this->providerRegistry($this->providerBundle(Exchange::BITMART, MarketType::PERPETUAL)),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(),
            ['BITMART_API_KEY' => '', 'BITMART_SECRET_KEY' => '', 'BITMART_API_MEMO' => ''],
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Exchange: bitmart', $output);
        self::assertStringContainsString('Adapter: found', $output);
        self::assertStringContainsString('Provider bundle: found', $output);
        self::assertStringContainsString('Credentials: missing', $output);
        self::assertStringContainsString('Live trading: disabled', $output);
        self::assertStringContainsString('Recommended dry_run: true', $output);
        self::assertStringContainsString('Schedule ready: yes', $output);
    }

    private function adapter(
        Exchange $exchange,
        MarketType $marketType,
        bool $supportsPrivateWs = false,
        bool $supportsTriggerOrders = false,
    ): ExchangeAdapterInterface {
        $adapter = $this->createMock(ExchangeAdapterInterface::class);
        $adapter->method('exchange')->willReturn($exchange);
        $adapter->method('marketType')->willReturn($marketType);
        $adapter->method('capabilities')->willReturn(new ExchangeCapabilities(
            supportsWebSocketPrivate: $supportsPrivateWs,
            supportsTriggerOrders: $supportsTriggerOrders,
        ));

        return $adapter;
    }

    private function adapterRegistry(ExchangeAdapterInterface $adapter): ExchangeAdapterRegistryInterface
    {
        $registry = $this->createMock(ExchangeAdapterRegistryInterface::class);
        $registry
            ->method('get')
            ->with($adapter->exchange(), $adapter->marketType())
            ->willReturn($adapter);

        return $registry;
    }

    private function missingProviderRegistry(): ExchangeProviderRegistryInterface
    {
        $registry = $this->createMock(ExchangeProviderRegistryInterface::class);
        $registry
            ->method('get')
            ->willThrowException(new \RuntimeException('provider missing'));

        return $registry;
    }

    private function providerRegistry(ExchangeProviderBundle $bundle): ExchangeProviderRegistryInterface
    {
        $registry = $this->createMock(ExchangeProviderRegistryInterface::class);
        $registry
            ->method('get')
            ->with(self::callback(static fn (ExchangeContext $context): bool => (string) $context === (string) $bundle->context()))
            ->willReturn($bundle);

        return $registry;
    }

    private function providerBundle(
        Exchange $exchange,
        MarketType $marketType,
        bool $contractsLoaded = true,
        bool $accountReadable = true,
    ): ExchangeProviderBundle
    {
        $contractProvider = $this->createMock(ContractProviderInterface::class);
        $contractProvider->method('getContracts')->willReturn($contractsLoaded ? [['symbol' => 'BTCUSDT']] : []);
        $accountProvider = $this->createMock(AccountProviderInterface::class);
        $accountProvider->method('getAccountInfo')->willReturn($accountReadable ? AccountDto::fromArray([
            'currency' => 'USDT',
            'available_balance' => '100',
            'frozen_balance' => '0',
            'unrealized' => '0',
            'equity' => '100',
            'position_deposit' => '0',
        ]) : null);

        return new ExchangeProviderBundle(
            new ExchangeContext($exchange, $marketType),
            $this->createMock(KlineProviderInterface::class),
            $contractProvider,
            $this->createMock(OrderProviderInterface::class),
            $accountProvider,
            $this->createMock(SystemProviderInterface::class),
        );
    }
}
