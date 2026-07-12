<?php

declare(strict_types=1);

namespace App\Tests\Provider\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\Dto\AccountDto;
use App\Contract\Provider\ExchangeProviderRegistryInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\SystemProviderInterface;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Contract\ExchangeAdapterRegistryInterface;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidPollingObservabilityPolicy;
use App\Exchange\Hyperliquid\HyperliquidRestClientInterface;
use App\Exchange\Hyperliquid\HyperliquidSignedActionClientInterface;
use App\Exchange\Hyperliquid\HyperliquidSignedActionResult;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessInput;
use App\Provider\Context\ExchangeContext;
use App\Provider\Hyperliquid\HyperliquidMutationReadinessProbe;
use App\Provider\Hyperliquid\HyperliquidMutationReadinessProbeInterface;
use App\Provider\Hyperliquid\HyperliquidNonceManagerInterface;
use App\Provider\Hyperliquid\HyperliquidNonceScope;
use App\Provider\Hyperliquid\HyperliquidRuntimeCheck;
use App\Provider\Registry\ExchangeProviderBundle;
use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchTripInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidMutationReadinessProbe::class)]
#[CoversClass(HyperliquidRuntimeCheck::class)]
final class HyperliquidMutationReadinessProbeTest extends TestCase
{
    private const ACCOUNT = '0x0000000000000000000000000000000000000001';
    private const AGENT = '0x0000000000000000000000000000000000000002';
    private const OTHER_AGENT = '0x0000000000000000000000000000000000000003';

    public function testCurrentReturnsSoleTypedCandidateFromStrictReadOnlyEvidenceWithoutPhpKeyCustody(): void
    {
        $rest = new RecordingInfoClient($this->validExtraAgents());
        $probe = $this->probe(rest: $rest);

        self::assertInstanceOf(HyperliquidMutationReadinessProbeInterface::class, $probe);
        $report = $probe->current();

        self::assertSame(ExchangeReadinessLevel::DemoTestnetCandidate, $report->readyLevel);
        self::assertTrue($report->permissionsTrade);
        self::assertTrue($report->privateObservability);
        self::assertTrue($report->pollingReady);
        self::assertTrue($report->signerConfigured);
        self::assertTrue($report->signerMatchesAccount);
        self::assertTrue($report->nonceStoreReady);
        self::assertFalse($report->killSwitch);
        self::assertSame([], $report->blockingErrors);
        self::assertSame([], $report->warnings);
        self::assertSame([['type' => 'extraAgents', 'user' => self::ACCOUNT]], $rest->requests);
    }

    public function testRuntimeCheckUsesProbeInsteadOfForgeableInputWhenProbeIsAvailable(): void
    {
        $report = (new HyperliquidRuntimeCheck(probe: $this->probe()))->check(new ExchangeReadinessInput(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::SPOT,
            environment: 'mainnet',
        ));

        self::assertSame(ExchangeReadinessLevel::DemoTestnetCandidate, $report->readyLevel);
        self::assertSame(MarketType::PERPETUAL, $report->marketType);
        self::assertTrue($report->permissionsTrade);
    }

    /** @param array<mixed> $extraAgents */
    #[DataProvider('invalidExtraAgents')]
    public function testDoesNotProveTradePermissionFromInvalidExtraAgents(
        array $extraAgents,
        string $warning,
    ): void {
        $report = $this->probe(rest: new RecordingInfoClient($extraAgents))->current();

        self::assertFalse($report->permissionsTrade);
        self::assertContains($warning, $report->warnings);
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidExtraAgents(): iterable
    {
        yield 'absent' => [[], 'hyperliquid_agent_wallet_trade_permission_not_proven'];
        yield 'expired' => [[['address' => self::AGENT, 'validUntil' => 1_783_857_600_000]], 'hyperliquid_agent_wallet_trade_permission_expired'];
        yield 'malformed row' => [[['address' => self::AGENT]], 'hyperliquid_extra_agents_response_malformed'];
        yield 'malformed address' => [[['address' => 'agent', 'validUntil' => 1_900_000_000_000]], 'hyperliquid_extra_agents_response_malformed'];
        yield 'wrong agent' => [[['address' => self::OTHER_AGENT, 'validUntil' => 1_900_000_000_000]], 'hyperliquid_agent_wallet_trade_permission_not_proven'];
    }

    public function testNormalizesConfiguredAndObservedAgentAddresses(): void
    {
        $report = $this->probe(
            config: $this->config(agent: strtoupper(self::AGENT)),
            rest: new RecordingInfoClient([['address' => strtoupper(self::AGENT), 'validUntil' => 1_900_000_000_000]]),
        )->current();

        self::assertTrue($report->permissionsTrade);
    }

    public function testSidecarMustProveHealthAgentEqualityAndBroadcast(): void
    {
        $report = $this->probe(sidecarHealthy: false)->current();

        self::assertFalse($report->signerConfigured);
        self::assertFalse($report->signerMatchesAccount);
        self::assertContains('hyperliquid_signer_sidecar_not_ready', $report->warnings);
    }

    public function testNonceScopeMustBePersistentlyReady(): void
    {
        $report = $this->probe(nonceReady: false)->current();

        self::assertFalse($report->nonceStoreReady);
        self::assertContains('hyperliquid_nonce_store_not_ready', $report->warnings);
    }

    #[DataProvider('strictReadFailures')]
    public function testEveryStrictReadFailureRemainsFailClosed(string $failedRead, string $warning): void
    {
        $report = $this->probe(failedRead: $failedRead)->current();

        self::assertNotSame(ExchangeReadinessLevel::DemoTestnetCandidate, $report->readyLevel);
        self::assertContains($warning, $report->warnings);
    }

    /** @return iterable<string, array{string, string}> */
    public static function strictReadFailures(): iterable
    {
        yield 'public' => ['public', 'hyperliquid_public_read_probe_failed'];
        yield 'account' => ['account', 'hyperliquid_account_read_probe_failed'];
        yield 'open orders' => ['orders', 'hyperliquid_open_orders_poll_failed'];
        yield 'fills' => ['fills', 'hyperliquid_fills_poll_failed'];
        yield 'positions' => ['positions', 'hyperliquid_positions_poll_failed'];
    }

    public function testDurableAndEnvironmentKillSwitchesRemainEffective(): void
    {
        $durable = $this->probe(durableKillSwitch: true, environmentKillSwitch: false)->current();
        $environment = $this->probe(durableKillSwitch: false, environmentKillSwitch: true)->current();

        self::assertTrue($durable->killSwitch);
        self::assertTrue($environment->killSwitch);
    }

    public function testDefaultConfigurationRemainsFailClosed(): void
    {
        $report = $this->probe(
            environmentKillSwitch: true,
            allowedMarkets: [],
            maxNotional: null,
        )->current();

        self::assertNotSame(ExchangeReadinessLevel::DemoTestnetCandidate, $report->readyLevel);
        self::assertTrue($report->killSwitch);
        self::assertSame([], $report->allowedMarkets);
        self::assertNull($report->maxNotional);
    }

    public function testInvalidMaxNotionalFailsClosedWithoutThrowing(): void
    {
        $report = $this->probe(maxNotional: 0.0)->current();

        self::assertNotSame(ExchangeReadinessLevel::DemoTestnetCandidate, $report->readyLevel);
        self::assertNull($report->maxNotional);
        self::assertContains('positive_max_notional_required', $report->warnings);
    }

    public function testSpoofedEndpointPreventsEveryExchangeInfoProbe(): void
    {
        $rest = new RecordingInfoClient($this->validExtraAgents());
        $report = $this->probe(
            config: new HyperliquidConfig(
                environment: 'testnet',
                apiBaseUri: 'https://api.hyperliquid-testnet.xyz.attacker.invalid',
                network: 'testnet',
                testnetAgentAddress: self::AGENT,
                testnetAccountAddress: self::ACCOUNT,
                testnetTradingEnabled: true,
            ),
            rest: $rest,
        )->current();

        self::assertSame([], $rest->requests);
        self::assertFalse($report->permissionsTrade);
        self::assertContains('hyperliquid_testnet_endpoint_guard_not_ready', $report->warnings);
    }

    public function testDisabledTradingFeatureFlagCannotProduceCandidate(): void
    {
        $report = $this->probe(config: new HyperliquidConfig(
            environment: 'testnet',
            apiBaseUri: 'https://api.hyperliquid-testnet.xyz',
            network: 'testnet',
            testnetAgentAddress: self::AGENT,
            testnetAccountAddress: self::ACCOUNT,
            testnetTradingEnabled: false,
        ))->current();

        self::assertNotSame(ExchangeReadinessLevel::DemoTestnetCandidate, $report->readyLevel);
        self::assertFalse($report->demoTestnetWriteGuard);
    }

    /** @param list<string> $allowedMarkets */
    private function probe(
        ?HyperliquidConfig $config = null,
        ?RecordingInfoClient $rest = null,
        bool $sidecarHealthy = true,
        bool $nonceReady = true,
        bool $durableKillSwitch = false,
        bool $environmentKillSwitch = false,
        array $allowedMarkets = ['perpetual'],
        ?float $maxNotional = 25.0,
        ?string $failedRead = null,
    ): HyperliquidMutationReadinessProbe {
        $clock = new MockClock('2026-07-12T12:00:00.000Z');

        return new HyperliquidMutationReadinessProbe(
            config: $config ?? $this->config(),
            adapters: $this->adapterRegistry(),
            providers: $this->providerRegistry($failedRead),
            restClient: $rest ?? new RecordingInfoClient($this->validExtraAgents()),
            signedClient: $this->signedClient($sidecarHealthy),
            nonceManager: $this->nonceManager($nonceReady),
            durableKillSwitch: $this->killSwitch($durableKillSwitch),
            pollingPolicy: new HyperliquidPollingObservabilityPolicy($clock),
            clock: $clock,
            environmentKillSwitchEnabled: $environmentKillSwitch,
            reconciliationInFlight: false,
            allowedMarkets: $allowedMarkets,
            maxNotional: $maxNotional,
        );
    }

    private function config(string $agent = self::AGENT): HyperliquidConfig
    {
        return new HyperliquidConfig(
            environment: 'testnet',
            apiBaseUri: 'https://api.hyperliquid-testnet.xyz',
            network: 'testnet',
            testnetAgentPrivateKey: '',
            testnetAgentAddress: $agent,
            testnetAccountAddress: self::ACCOUNT,
            testnetTradingEnabled: true,
        );
    }

    private function adapterRegistry(): ExchangeAdapterRegistryInterface
    {
        $adapter = $this->createMock(ExchangeAdapterInterface::class);
        $adapter->method('capabilities')->willReturn(new ExchangeCapabilities(supportsTriggerOrders: true));
        $registry = $this->createMock(ExchangeAdapterRegistryInterface::class);
        $registry->method('get')->willReturn($adapter);

        return $registry;
    }

    private function providerRegistry(?string $failedRead): ExchangeProviderRegistryInterface
    {
        $contracts = $this->createMock(ContractProviderInterface::class);
        $contracts->method('getContracts')->willReturnCallback(
            static fn (): array => $failedRead === 'public' ? throw new \RuntimeException('failed') : [['symbol' => 'BTCUSDT']],
        );

        $account = $this->createMock(AccountProviderInterface::class);
        $account->method('getAccountInfo')->willReturnCallback(
            fn (): AccountDto => $failedRead === 'account' ? throw new \RuntimeException('failed') : $this->account(),
        );
        $account->method('getOpenPositionsOrFail')->willReturnCallback(
            static fn (): array => $failedRead === 'positions' ? throw new \RuntimeException('failed') : [],
        );
        $account->method('getTrades')->willReturnCallback(
            static fn (): array => $failedRead === 'fills' ? throw new \RuntimeException('failed') : [],
        );

        $orders = $this->createMock(OrderProviderInterface::class);
        $orders->method('getOpenOrdersOrFail')->willReturnCallback(
            static fn (): array => $failedRead === 'orders' ? throw new \RuntimeException('failed') : [],
        );

        $bundle = new ExchangeProviderBundle(
            new ExchangeContext(Exchange::HYPERLIQUID, MarketType::PERPETUAL),
            $this->createMock(KlineProviderInterface::class),
            $contracts,
            $orders,
            $account,
            $this->createMock(SystemProviderInterface::class),
        );
        $registry = $this->createMock(ExchangeProviderRegistryInterface::class);
        $registry->method('get')->willReturn($bundle);

        return $registry;
    }

    private function account(): AccountDto
    {
        return AccountDto::fromArray([
            'currency' => 'USDC',
            'available_balance' => '100',
            'frozen_balance' => '0',
            'unrealized' => '0',
            'equity' => '100',
            'position_deposit' => '0',
        ]);
    }

    private function signedClient(bool $healthy): HyperliquidSignedActionClientInterface
    {
        return new class($healthy) implements HyperliquidSignedActionClientInterface {
            public function __construct(private readonly bool $healthy)
            {
            }

            public function submit(array $action, int $nonce, string $correlationId, ?int $expiresAfter = null): HyperliquidSignedActionResult
            {
                throw new \LogicException('readiness_probe_must_not_mutate');
            }

            public function health(): bool
            {
                return $this->healthy;
            }
        };
    }

    private function nonceManager(bool $ready): HyperliquidNonceManagerInterface
    {
        return new class($ready) implements HyperliquidNonceManagerInterface {
            public function __construct(private readonly bool $ready)
            {
            }

            public function isReady(HyperliquidNonceScope $scope): bool
            {
                return $this->ready;
            }

            public function nextNonce(HyperliquidNonceScope $scope): int
            {
                throw new \LogicException('readiness_probe_must_not_reserve_nonce');
            }

            public function recordObservedNonce(HyperliquidNonceScope $scope, int $nonce): void
            {
                throw new \LogicException('readiness_probe_must_not_record_nonce');
            }
        };
    }

    private function killSwitch(bool $tripped): HyperliquidKillSwitchTripInterface
    {
        return new class($tripped) implements HyperliquidKillSwitchTripInterface {
            public function __construct(private readonly bool $tripped)
            {
            }

            public function isTripped(): bool
            {
                return $this->tripped;
            }

            public function trip(string $reason, array $auditContext): void
            {
                throw new \LogicException('readiness_probe_must_not_trip_switch');
            }
        };
    }

    /** @return list<array{address: string, validUntil: int}> */
    private function validExtraAgents(): array
    {
        return [['address' => self::AGENT, 'validUntil' => 1_900_000_000_000]];
    }
}

final class RecordingInfoClient implements HyperliquidRestClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @param array<mixed> $response */
    public function __construct(private readonly array $response)
    {
    }

    public function info(array $request): array
    {
        $this->requests[] = $request;

        return $this->response;
    }

    public function exchange(array $action): array
    {
        throw new \LogicException('readiness_probe_must_not_mutate');
    }
}
