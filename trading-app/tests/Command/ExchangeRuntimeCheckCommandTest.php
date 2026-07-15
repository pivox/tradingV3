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
use App\Exchange\Hyperliquid\HttpHyperliquidSignedActionClient;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketObservabilityStatus;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketRedisClientInterface;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketStatusStoreInterface;
use App\Exchange\Okx\PrivateWebSocket\RedisOkxPrivateWebSocketStatusStore;
use App\Exchange\Okx\OkxRestClientInterface;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\Provider\Context\ExchangeContext;
use App\Provider\Hyperliquid\HyperliquidMutationReadinessProbeInterface;
use App\Provider\Okx\OkxAccountGateway;
use App\Provider\Registry\ExchangeProviderBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;

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

    public function testOkxRuntimeCheckAcceptsReadableDemoAccountWithoutBalances(): void
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
                accountProvider: new OkxAccountGateway(new ReadableEmptyOkxAccountClient()),
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
        self::assertSame(Command::SUCCESS, $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]));

        $output = $tester->getDisplay();
        self::assertStringContainsString('Readiness level: demo_testnet_candidate', $output);
        self::assertStringNotContainsString('okx_private_read_probe_failed', $output);
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

    public function testHyperliquidSchedulesOnlyFromProvenMutationReadinessProbe(): void
    {
        $config = new HyperliquidConfig(
            environment: 'testnet',
            apiBaseUri: 'https://api.hyperliquid-testnet.xyz',
            network: 'testnet',
            testnetAgentAddress: '0x0000000000000000000000000000000000000002',
            testnetAccountAddress: '0x0000000000000000000000000000000000000001',
            testnetTradingEnabled: true,
            globalDemoTradingEnabled: true,
        );
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(Exchange::HYPERLIQUID, MarketType::PERPETUAL)),
            $this->providerRegistry($this->providerBundle(Exchange::HYPERLIQUID, MarketType::PERPETUAL)),
            new OkxConfig(environment: 'demo'),
            $config,
            [],
            $this->readyMutationProbe(),
        );

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([
            'exchange' => 'hyperliquid',
            'market_type' => 'perpetual',
        ]));

        $output = $tester->getDisplay();
        self::assertStringContainsString('Readiness level: demo_testnet_candidate', $output);
        self::assertStringContainsString('Dry-run only: no', $output);
        self::assertStringContainsString('Live allowed: yes', $output);
        self::assertStringContainsString('Recommended dry_run: false', $output);
        self::assertStringContainsString('Schedule ready: yes', $output);
    }

    public function testOkxRuntimeCheckReportsHealthyPrivateWebSocketObservabilityWithoutRelaxingSafetyGuards(): void
    {
        $client = new ReadableEmptyOkxAccountClient();
        $tester = new CommandTester($this->okxDemoCommand(
            $this->statusStore($this->privateWebSocketStatus()),
            accountProvider: new OkxAccountGateway($client),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]));

        $output = $tester->getDisplay();
        self::assertStringContainsString('Private WS: enabled', $output);
        self::assertStringContainsString('Private WS observability: ready', $output);
        self::assertStringContainsString('Readiness level: demo_testnet_candidate', $output);
        self::assertStringContainsString('Readiness warnings: none', $output);
        self::assertStringContainsString('Live trading: disabled', $output);
        self::assertStringContainsString('Dry-run only: yes', $output);
        self::assertStringContainsString('Live allowed: no', $output);
        self::assertStringContainsString('Mainnet write guard: yes', $output);
        self::assertStringContainsString('Demo/testnet write guard: yes', $output);
        self::assertStringContainsString('Kill switch: enabled', $output);
        self::assertStringContainsString('Recommended dry_run: true', $output);
        self::assertSame(0, $client->privatePostCalls);
    }

    public function testOkxPrivateWebSocketCapabilityAloneNeverGrantsObservabilityReadiness(): void
    {
        $tester = new CommandTester($this->okxDemoCommand());

        self::assertSame(Command::SUCCESS, $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]));

        $output = $tester->getDisplay();
        self::assertStringContainsString('Private WS: enabled', $output);
        self::assertStringContainsString('Private WS observability: not_ready', $output);
        self::assertStringContainsString('Readiness warnings: private_observability_absent_for_dry_run', $output);
    }

    public function testOkxPrivateWebSocketObservabilityFailsClosedWhenStatusIsAbsent(): void
    {
        $tester = new CommandTester($this->okxDemoCommand($this->statusStore(null)));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]));

        self::assertStringContainsString('Private WS observability: not_ready', $tester->getDisplay());
        self::assertStringContainsString('private_observability_absent_for_dry_run', $tester->getDisplay());
    }

    public function testOkxPrivateWebSocketObservabilityFailsClosedWhenStatusIsStale(): void
    {
        $staleAt = new \DateTimeImmutable('2026-07-13T09:59:58+00:00');
        $tester = new CommandTester($this->okxDemoCommand($this->statusStore($this->privateWebSocketStatus($staleAt))));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]));

        self::assertStringContainsString('Private WS observability: not_ready', $tester->getDisplay());
        self::assertStringContainsString('private_observability_absent_for_dry_run', $tester->getDisplay());
    }

    public function testOkxPrivateWebSocketObservabilityFailsClosedWhileReconnecting(): void
    {
        $tester = new CommandTester($this->okxDemoCommand($this->statusStore($this->privateWebSocketStatus(reconnecting: true))));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]));

        self::assertStringContainsString('Private WS observability: not_ready', $tester->getDisplay());
        self::assertStringContainsString('private_observability_absent_for_dry_run', $tester->getDisplay());
    }

    public function testOkxPrivateWebSocketStoreFailureIsCanonicalAndRedacted(): void
    {
        $tester = new CommandTester($this->okxDemoCommand($this->failingRedisStatusStore()));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]));

        $output = $tester->getDisplay();
        self::assertStringContainsString('Private WS observability: not_ready', $output);
        self::assertStringContainsString('okx_private_observability_status_store_unavailable', $output);
        self::assertStringContainsString('private_observability_absent_for_dry_run', $output);
        self::assertStringNotContainsString('okx_private_ws_status_read_failed', $output);
        self::assertStringNotContainsString('redis-password=highly-sensitive', $output);
        self::assertStringNotContainsString('947', $output);
    }

    public function testOkxPrivateWebSocketStoreFailurePreservesPublicAndPrivateReadWarnings(): void
    {
        $contractProvider = $this->createMock(ContractProviderInterface::class);
        $contractProvider->method('getContracts')->willThrowException(new \RuntimeException('public read failed'));
        $accountProvider = $this->createMock(AccountProviderInterface::class);
        $accountProvider->method('getAccountInfo')->willReturn(null);
        $orderProvider = $this->writeGuardedOrderProvider();
        $bundle = new ExchangeProviderBundle(
            new ExchangeContext(Exchange::OKX, MarketType::PERPETUAL),
            $this->createMock(KlineProviderInterface::class),
            $contractProvider,
            $orderProvider,
            $accountProvider,
            $this->createMock(SystemProviderInterface::class),
        );
        $store = $this->failingRedisStatusStore();
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(
                Exchange::OKX,
                MarketType::PERPETUAL,
                supportsPrivateWs: true,
                supportsTriggerOrders: true,
            )),
            $this->providerRegistry($bundle),
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
            [],
            null,
            $store,
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]));

        $output = $tester->getDisplay();
        self::assertStringContainsString('okx_public_read_probe_failed', $output);
        self::assertStringContainsString('okx_private_read_probe_failed', $output);
        self::assertStringContainsString('okx_private_observability_status_store_unavailable', $output);
        self::assertStringContainsString('Private WS observability: not_ready', $output);
        self::assertStringNotContainsString('redis-password=highly-sensitive', $output);
        self::assertStringNotContainsString('947', $output);
    }

    public function testOkxPrivateWebSocketObservabilityIsReadOnlyWhenDemoTradingIsDisabled(): void
    {
        $client = new ReadableEmptyOkxAccountClient();
        $tester = new CommandTester($this->okxDemoCommand(
            $this->statusStore($this->privateWebSocketStatus()),
            new OkxConfig(
                environment: 'demo',
                apiKey: 'key',
                apiSecret: 'secret',
                apiPassphrase: 'pass',
                simulatedTrading: true,
                demoTradingEnabled: false,
                liveEnabled: false,
            ),
            new OkxAccountGateway($client),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'exchange' => 'okx',
            'market_type' => 'perpetual',
        ]));

        $output = $tester->getDisplay();
        self::assertStringContainsString('Private WS observability: ready', $output);
        self::assertStringContainsString('Demo trading enabled: no', $output);
        self::assertStringContainsString('Live allowed: no', $output);
        self::assertStringContainsString('Kill switch: enabled', $output);
        self::assertStringNotContainsString('Readiness level: demo_testnet_enabled', $output);
        self::assertSame(0, $client->privatePostCalls);
    }

    public function testPrivateWebSocketStatusStoreIsNotReadOutsideOkxDemoCandidate(): void
    {
        $store = $this->createMock(OkxPrivateWebSocketStatusStoreInterface::class);
        $store->expects(self::never())->method('load');

        $commands = [
            [
                new ExchangeRuntimeCheckCommand(
                    $this->adapterRegistry($this->adapter(Exchange::BITMART, MarketType::PERPETUAL)),
                    $this->providerRegistry($this->providerBundle(Exchange::BITMART, MarketType::PERPETUAL)),
                    new OkxConfig(environment: 'demo'),
                    new HyperliquidConfig(),
                    [],
                    null,
                    $store,
                ),
                'bitmart',
            ],
            [
                new ExchangeRuntimeCheckCommand(
                    $this->adapterRegistry($this->adapter(Exchange::HYPERLIQUID, MarketType::PERPETUAL)),
                    $this->providerRegistry($this->providerBundle(Exchange::HYPERLIQUID, MarketType::PERPETUAL)),
                    new OkxConfig(environment: 'demo'),
                    new HyperliquidConfig(),
                    [],
                    null,
                    $store,
                ),
                'hyperliquid',
            ],
            [
                $this->okxDemoCommand($store, new OkxConfig(
                    environment: 'demo',
                    simulatedTrading: false,
                    demoTradingEnabled: true,
                    liveEnabled: false,
                )),
                'okx',
            ],
            [
                $this->okxDemoCommand($store, new OkxConfig(
                    environment: 'demo',
                    simulatedTrading: true,
                    demoTradingEnabled: true,
                    liveEnabled: true,
                )),
                'okx',
            ],
        ];

        foreach (['live', 'mainnet', '', '   ', 'staging', ' demo ', 'DEMO'] as $environment) {
            $commands[] = [
                $this->okxDemoCommand($store, new OkxConfig(
                    environment: $environment,
                    apiKey: 'key',
                    apiSecret: 'secret',
                    apiPassphrase: 'pass',
                    simulatedTrading: true,
                    demoTradingEnabled: true,
                    liveEnabled: false,
                )),
                'okx',
            ];
        }

        foreach ($commands as [$command, $exchange]) {
            $tester = new CommandTester($command);
            self::assertSame(Command::SUCCESS, $tester->execute([
                'exchange' => $exchange,
                'market_type' => 'perpetual',
            ]));
            if ($exchange === 'okx') {
                self::assertStringContainsString('Private WS observability: not_ready', $tester->getDisplay());
            }
        }
    }

    public function testHyperliquidRuntimeDoesNotAbortWhenAddressesExistWithoutSignerToken(): void
    {
        $account = '0x0000000000000000000000000000000000000001';
        $agent = '0x0000000000000000000000000000000000000002';
        $signedClient = new HttpHyperliquidSignedActionClient(
            new MockHttpClient(),
            'http://hyperliquid-signer:8098',
            '',
            $account,
            $agent,
        );
        $config = new HyperliquidConfig(
            environment: 'testnet',
            apiBaseUri: 'https://api.hyperliquid-testnet.xyz',
            network: 'testnet',
            testnetAgentAddress: $agent,
            testnetAccountAddress: $account,
            testnetTradingEnabled: true,
            globalDemoTradingEnabled: true,
        );
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(Exchange::HYPERLIQUID, MarketType::PERPETUAL)),
            $this->providerRegistry($this->providerBundle(Exchange::HYPERLIQUID, MarketType::PERPETUAL)),
            new OkxConfig(environment: 'demo'),
            $config,
            [],
            $this->mutationProbe(signerReady: $signedClient->health()),
        );

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([
            'exchange' => 'hyperliquid',
            'market_type' => 'perpetual',
        ]));

        $output = $tester->getDisplay();
        self::assertStringContainsString('Signer configured: no', $output);
        self::assertStringContainsString('Live allowed: no', $output);
        self::assertStringContainsString('Schedule ready: no', $output);
    }

    public function testHyperliquidWithoutProbeRemainsBlockedEvenWithLegacyReadinessInputs(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(
                Exchange::HYPERLIQUID,
                MarketType::PERPETUAL,
                supportsPrivateWs: true,
                supportsTriggerOrders: true,
            )),
            $this->providerRegistry($this->providerBundle(Exchange::HYPERLIQUID, MarketType::PERPETUAL)),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(
                environment: 'testnet',
                network: 'testnet',
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
        self::assertStringContainsString('Testnet trading enabled: no', $output);
        self::assertStringContainsString('Readiness level: not_ready', $output);
        self::assertStringContainsString('Readiness blocking errors: hyperliquid_mutation_readiness_probe_unavailable', $output);
        self::assertStringContainsString('Signer configured: no', $output);
        self::assertStringContainsString('Signer/account relation: no', $output);
        self::assertStringContainsString('Nonce store: not_ready', $output);
        self::assertStringContainsString('Collateral readable: no', $output);
        self::assertStringContainsString('WS/polling: not_ready', $output);
        self::assertStringContainsString('Stop loss capability: no', $output);
        self::assertStringContainsString('Kill switch: enabled', $output);
        self::assertStringContainsString('Recommended dry_run: true', $output);
        self::assertStringContainsString('Schedule ready: no', $output);
    }

    public function testHyperliquidFeatureFlagCannotForgeCandidateWithoutProbe(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(
                Exchange::HYPERLIQUID,
                MarketType::PERPETUAL,
                supportsPrivateWs: true,
                supportsTriggerOrders: true,
            )),
            $this->providerRegistry($this->providerBundle(Exchange::HYPERLIQUID, MarketType::PERPETUAL)),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(
                environment: 'testnet',
                network: 'testnet',
                testnetAgentAddress: '0xagent',
                testnetAccountAddress: '0xabc',
                testnetTradingEnabled: true,
            ),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'hyperliquid',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Testnet trading enabled: yes', $output);
        self::assertStringContainsString('Readiness level: not_ready', $output);
        self::assertStringContainsString('Readiness blocking errors: hyperliquid_mutation_readiness_probe_unavailable', $output);
        self::assertStringNotContainsString('Readiness level: demo_testnet_enabled', $output);
        self::assertStringNotContainsString('0xagent', $output);
        self::assertStringNotContainsString('0xabc', $output);
        self::assertStringContainsString('Live allowed: no', $output);
        self::assertStringContainsString('Recommended dry_run: true', $output);
        self::assertStringContainsString('Schedule ready: no', $output);
    }

    public function testHyperliquidDoesNotTrustSpoofedTestnetEndpointHost(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(
                Exchange::HYPERLIQUID,
                MarketType::PERPETUAL,
                supportsPrivateWs: true,
                supportsTriggerOrders: true,
            )),
            $this->providerRegistry($this->providerBundle(Exchange::HYPERLIQUID, MarketType::PERPETUAL)),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(
                environment: 'testnet',
                apiBaseUri: 'https://api.hyperliquid-testnet.xyz.evil.example',
                wsUri: 'wss://api.hyperliquid-testnet.xyz.evil.example/ws',
                network: 'testnet',
                testnetAgentAddress: '0xagent',
                testnetAccountAddress: '0xabc',
                testnetTradingEnabled: true,
            ),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'hyperliquid',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Readiness level: not_ready', $output);
        self::assertStringContainsString('hyperliquid_mutation_readiness_probe_unavailable', $output);
        self::assertStringContainsString('Demo/testnet write guard: no', $output);
        self::assertStringContainsString('Schedule ready: no', $output);
    }

    public function testHyperliquidSkipsProviderProbesWhenEndpointGuardFails(): void
    {
        $contractProvider = $this->createMock(ContractProviderInterface::class);
        $contractProvider->expects(self::never())->method('getContracts');
        $accountProvider = $this->createMock(AccountProviderInterface::class);
        $accountProvider->expects(self::never())->method('getAccountInfo');
        $bundle = new ExchangeProviderBundle(
            new ExchangeContext(Exchange::HYPERLIQUID, MarketType::PERPETUAL),
            $this->createMock(KlineProviderInterface::class),
            $contractProvider,
            $this->createMock(OrderProviderInterface::class),
            $accountProvider,
            $this->createMock(SystemProviderInterface::class),
        );

        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(
                Exchange::HYPERLIQUID,
                MarketType::PERPETUAL,
                supportsPrivateWs: true,
                supportsTriggerOrders: true,
            )),
            $this->providerRegistry($bundle),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(
                environment: 'testnet',
                apiBaseUri: 'https://api.hyperliquid-testnet.xyz.evil.example',
                wsUri: 'wss://api.hyperliquid-testnet.xyz.evil.example/ws',
                network: 'testnet',
                testnetAgentAddress: '0xagent',
                testnetAccountAddress: '0xabc',
                testnetTradingEnabled: true,
            ),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'hyperliquid',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Readiness level: not_ready', $output);
        self::assertStringContainsString('hyperliquid_mutation_readiness_probe_unavailable', $output);
        self::assertStringContainsString('Schedule ready: no', $output);
    }

    public function testHyperliquidDoesNotReachLocalDryRunWhenEndpointIsNotTestnet(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(
                Exchange::HYPERLIQUID,
                MarketType::PERPETUAL,
                supportsPrivateWs: true,
                supportsTriggerOrders: true,
            )),
            $this->providerRegistry($this->providerBundle(Exchange::HYPERLIQUID, MarketType::PERPETUAL)),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(
                environment: 'testnet',
                apiBaseUri: 'https://api.hyperliquid.xyz',
                wsUri: 'wss://api.hyperliquid.xyz/ws',
                network: 'testnet',
                testnetAgentAddress: '0xagent',
                testnetAccountAddress: '0xabc',
                testnetTradingEnabled: true,
            ),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'hyperliquid',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Readiness level: not_ready', $output);
        self::assertStringContainsString('hyperliquid_mutation_readiness_probe_unavailable', $output);
        self::assertStringContainsString('Demo/testnet write guard: no', $output);
        self::assertStringContainsString('Schedule ready: no', $output);
    }

    public function testHyperliquidDoesNotReachLocalDryRunWhenConfiguredEnvironmentIsBlank(): void
    {
        $command = new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(
                Exchange::HYPERLIQUID,
                MarketType::PERPETUAL,
                supportsPrivateWs: true,
                supportsTriggerOrders: true,
            )),
            $this->providerRegistry($this->providerBundle(Exchange::HYPERLIQUID, MarketType::PERPETUAL)),
            new OkxConfig(environment: 'demo'),
            new HyperliquidConfig(
                environment: '',
                network: 'testnet',
                testnetAgentAddress: '0xagent',
                testnetAccountAddress: '0xabc',
                testnetTradingEnabled: true,
            ),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'exchange' => 'hyperliquid',
            'market_type' => 'perpetual',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Network: testnet', $output);
        self::assertStringContainsString('Readiness level: not_ready', $output);
        self::assertStringContainsString('Demo/testnet write guard: no', $output);
        self::assertStringContainsString('hyperliquid_mutation_readiness_probe_unavailable', $output);
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
        self::assertStringContainsString('Readiness blocking errors: hyperliquid_mutation_readiness_probe_unavailable', $output);
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
        $adapter->expects(self::never())->method('placeOrder');
        $adapter->expects(self::never())->method('cancelOrder');
        $adapter->expects(self::never())->method('setLeverage');

        return $adapter;
    }

    private function okxDemoCommand(
        ?OkxPrivateWebSocketStatusStoreInterface $store = null,
        ?OkxConfig $config = null,
        ?AccountProviderInterface $accountProvider = null,
        ?ClockInterface $clock = null,
    ): ExchangeRuntimeCheckCommand {
        return new ExchangeRuntimeCheckCommand(
            $this->adapterRegistry($this->adapter(
                Exchange::OKX,
                MarketType::PERPETUAL,
                supportsPrivateWs: true,
                supportsTriggerOrders: true,
            )),
            $this->providerRegistry($this->providerBundle(
                Exchange::OKX,
                MarketType::PERPETUAL,
                accountProvider: $accountProvider,
            )),
            $config ?? new OkxConfig(
                environment: 'demo',
                apiKey: 'key',
                apiSecret: 'secret',
                apiPassphrase: 'pass',
                simulatedTrading: true,
                demoTradingEnabled: true,
                liveEnabled: false,
            ),
            new HyperliquidConfig(),
            [],
            null,
            $store,
            clock: $clock ?? new MockClock('2026-07-13T10:00:09+00:00'),
        );
    }

    private function failingRedisStatusStore(): RedisOkxPrivateWebSocketStatusStore
    {
        return new RedisOkxPrivateWebSocketStatusStore(new class implements OkxPrivateWebSocketRedisClientInterface {
            public function setex(string $key, int $ttl, string $value): bool
            {
                throw new \LogicException('Unexpected Redis write.');
            }

            public function get(string $key): string|false
            {
                throw new \RuntimeException('redis-password=highly-sensitive', 947);
            }

            public function del(string $key): int|false
            {
                throw new \LogicException('Unexpected Redis delete.');
            }
        });
    }

    private function statusStore(?OkxPrivateWebSocketObservabilityStatus $status): OkxPrivateWebSocketStatusStoreInterface
    {
        $store = $this->createMock(OkxPrivateWebSocketStatusStoreInterface::class);
        $store->expects(self::once())->method('load')->willReturn($status);

        return $store;
    }

    private function privateWebSocketStatus(
        ?\DateTimeImmutable $observedAt = null,
        bool $reconnecting = false,
    ): OkxPrivateWebSocketObservabilityStatus {
        $observedAt ??= new \DateTimeImmutable('2026-07-13T10:00:09+00:00');

        return new OkxPrivateWebSocketObservabilityStatus(
            connected: true,
            authenticated: true,
            ordersStreamReady: true,
            fillsStreamReady: true,
            fillsSource: 'fills_channel',
            positionsStreamReady: true,
            initialSnapshotLoaded: true,
            reconciliationFresh: true,
            reconnecting: $reconnecting,
            connectedAt: $observedAt,
            lastHeartbeatAt: $observedAt,
            lastEventAt: $observedAt,
            observedAt: $observedAt,
            blockingErrors: [],
            warnings: [],
        );
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
        ?AccountProviderInterface $accountProvider = null,
    ): ExchangeProviderBundle
    {
        $contractProvider = $this->createMock(ContractProviderInterface::class);
        $contractProvider->method('getContracts')->willReturn($contractsLoaded ? [['symbol' => 'BTCUSDT']] : []);
        if (!$accountProvider instanceof AccountProviderInterface) {
            $accountProvider = $this->createMock(AccountProviderInterface::class);
            $accountProvider->method('getAccountInfo')->willReturn($accountReadable ? AccountDto::fromArray([
                'currency' => 'USDT',
                'available_balance' => '100',
                'frozen_balance' => '0',
                'unrealized' => '0',
                'equity' => '100',
                'position_deposit' => '0',
            ]) : null);
        }

        return new ExchangeProviderBundle(
            new ExchangeContext($exchange, $marketType),
            $this->createMock(KlineProviderInterface::class),
            $contractProvider,
            $this->writeGuardedOrderProvider(),
            $accountProvider,
            $this->createMock(SystemProviderInterface::class),
        );
    }

    private function writeGuardedOrderProvider(): OrderProviderInterface
    {
        $orderProvider = $this->createMock(OrderProviderInterface::class);
        $orderProvider->expects(self::never())->method('placeOrder');
        $orderProvider->expects(self::never())->method('cancelOrder');
        $orderProvider->expects(self::never())->method('cancelAllOrders');
        $orderProvider->expects(self::never())->method('submitLeverage');

        return $orderProvider;
    }

    private function readyMutationProbe(): HyperliquidMutationReadinessProbeInterface
    {
        return $this->mutationProbe(signerReady: true);
    }

    private function mutationProbe(bool $signerReady): HyperliquidMutationReadinessProbeInterface
    {
        return new class($signerReady) implements HyperliquidMutationReadinessProbeInterface {
            public function __construct(private readonly bool $signerReady)
            {
            }

            public function current(): ExchangeReadinessReport
            {
                return new ExchangeReadinessReport(
                    exchange: Exchange::HYPERLIQUID,
                    marketType: MarketType::PERPETUAL,
                    environment: 'testnet',
                    readyLevel: ExchangeReadinessLevel::DemoTestnetCandidate,
                    publicConnectivity: true,
                    privateReadConnectivity: true,
                    privateObservability: true,
                    privateObservabilityStatus: null,
                    instrumentsLoaded: true,
                    metadataValid: true,
                    precisionValid: true,
                    accountReadable: true,
                    permissionsRead: true,
                    permissionsTrade: true,
                    signerConfigured: $this->signerReady,
                    signerMatchesAccount: $this->signerReady,
                    nonceStoreReady: true,
                    collateralReadable: true,
                    pollingReady: true,
                    mainnetWriteGuard: true,
                    demoTestnetWriteGuard: true,
                    stopLossCapability: true,
                    killSwitch: false,
                    allowedSymbols: [],
                    allowedMarkets: ['perpetual'],
                    maxNotional: 25.0,
                    configHash: str_repeat('a', 64),
                    blockingErrors: [],
                    warnings: [],
                    configProfile: 'scalper_micro',
                );
            }
        };
    }
}

final class ReadableEmptyOkxAccountClient implements OkxRestClientInterface
{
    public int $privatePostCalls = 0;

    public function publicGet(string $path, array $query = []): array
    {
        throw new \LogicException('Public reads are not used by this fixture.');
    }

    public function privateGet(string $path, array $query = []): array
    {
        return ['code' => '0', 'data' => [['details' => []]]];
    }

    public function privatePost(string $path, array $body = []): array
    {
        ++$this->privatePostCalls;

        throw new \LogicException('Writes are not used by this fixture.');
    }
}
