<?php

declare(strict_types=1);

namespace App\Tests\Provider\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Common\Enum\Timeframe;
use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Contract\Provider\SystemProviderInterface;
use App\Exchange\Readiness\ExchangePrivateObservabilityStatus;
use App\Exchange\Readiness\ExchangeReadinessInput;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Provider\Context\ExchangeContext;
use App\Provider\Hyperliquid\HyperliquidAccountGateway;
use App\Provider\Hyperliquid\HyperliquidExecutionGateway;
use App\Provider\Hyperliquid\HyperliquidMarketGateway;
use App\Provider\Hyperliquid\HyperliquidMetadataProvider;
use App\Provider\Hyperliquid\HyperliquidNonceManagerInterface;
use App\Provider\Hyperliquid\HyperliquidProviderNotReadyException;
use App\Provider\Hyperliquid\HyperliquidRuntimeCheck;
use App\Provider\Hyperliquid\HyperliquidSignerInterface;
use App\Provider\Hyperliquid\HyperliquidSystemProvider;
use App\Provider\MainProvider;
use App\Provider\Registry\Exception\ProviderNotFoundException;
use App\Provider\Registry\ExchangeProviderBundle;
use App\Provider\Registry\ExchangeProviderRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class HyperliquidExchangeBundleRegistryTest extends TestCase
{
    public function testRegistryResolvesHyperliquidPerpetualBundle(): void
    {
        $registry = new ExchangeProviderRegistry(
            [
                $this->bitmartBundle(),
                $this->hyperliquidBundle(),
            ],
            Exchange::BITMART,
            MarketType::PERPETUAL,
        );

        $bundle = $registry->get(new ExchangeContext(Exchange::HYPERLIQUID, MarketType::PERPETUAL));

        self::assertTrue($bundle->context()->equals(new ExchangeContext(Exchange::HYPERLIQUID, MarketType::PERPETUAL)));
        self::assertInstanceOf(HyperliquidMarketGateway::class, $bundle->kline());
        self::assertInstanceOf(HyperliquidMetadataProvider::class, $bundle->contract());
        self::assertInstanceOf(HyperliquidExecutionGateway::class, $bundle->order());
        self::assertInstanceOf(HyperliquidAccountGateway::class, $bundle->account());
        self::assertInstanceOf(HyperliquidSystemProvider::class, $bundle->system());
    }

    public function testHyperliquidSpotDoesNotFallbackToBitmart(): void
    {
        $registry = new ExchangeProviderRegistry(
            [
                $this->bitmartBundle(),
                $this->hyperliquidBundle(),
            ],
            Exchange::BITMART,
            MarketType::PERPETUAL,
        );

        $this->expectException(ProviderNotFoundException::class);
        $this->expectExceptionMessage('hyperliquid::spot');

        $registry->get(new ExchangeContext(Exchange::HYPERLIQUID, MarketType::SPOT));
    }

    public function testMainProviderScopesToHyperliquidPerpetualBundle(): void
    {
        $mainProvider = new MainProvider(new ExchangeProviderRegistry(
            [
                $this->bitmartBundle(),
                $this->hyperliquidBundle(),
            ],
            Exchange::BITMART,
            MarketType::PERPETUAL,
        ));

        $scoped = $mainProvider->forContext(new ExchangeContext(Exchange::HYPERLIQUID, MarketType::PERPETUAL));

        $this->assertNotReady(
            static fn (): array => $scoped->getKlineProvider()->getKlines('BTCUSDT', Timeframe::TF_1M),
            'hyperliquid_market_data_not_ready',
        );
        $this->assertNotReady(
            static fn (): array => $scoped->getContractProvider()->getContracts(),
            'hyperliquid_metadata_not_ready',
        );
        $this->assertNotReady(
            static fn (): array => $scoped->getOrderProvider()->getOpenOrdersOrFail('BTCUSDT'),
            'hyperliquid_account_address_missing',
        );
        $this->assertNotReady(
            static fn (): array => $scoped->getAccountProvider()->getOpenPositionsOrFail('BTCUSDT'),
            'hyperliquid_account_address_missing',
        );
        self::assertInstanceOf(HyperliquidSystemProvider::class, $scoped->getSystemProvider());
    }

    public function testExecutionGatewayRejectsMutativeMethodsExplicitly(): void
    {
        $gateway = new HyperliquidExecutionGateway();

        $this->assertNotReady(
            static fn () => $gateway->placeOrder(
                'BTCUSDT',
                OrderSide::BUY,
                OrderType::LIMIT,
                1.0,
                50000.0,
            ),
            'hyperliquid_execution_not_ready',
        );
        $this->assertNotReady(
            static fn (): bool => $gateway->cancelOrder('BTCUSDT', 'oid-1'),
            'hyperliquid_execution_not_ready',
        );
        $this->assertNotReady(
            static fn (): bool => $gateway->submitLeverage('BTCUSDT', 2),
            'hyperliquid_execution_not_ready',
        );
    }

    public function testRuntimeCheckReachesLocalDryRunWhenActivationIsMissing(): void
    {
        $report = (new HyperliquidRuntimeCheck())->check(new ExchangeReadinessInput(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            environment: 'testnet',
            publicConnectivity: true,
            privateReadConnectivity: true,
            instrumentsLoaded: true,
            metadataValid: true,
            precisionValid: true,
            accountReadable: true,
            permissionsRead: true,
            permissionsTrade: true,
            signerConfigured: true,
            signerMatchesAccount: true,
            nonceStoreReady: true,
            collateralReadable: true,
            pollingReady: true,
            mainnetWriteGuard: true,
            demoTestnetWriteGuard: true,
            demoTestnetWriteEnabled: false,
            stopLossCapability: true,
            killSwitch: false,
            dryRun: false,
            allowedMarkets: ['perpetual'],
            maxNotional: 25.0,
        ));

        self::assertSame(ExchangeReadinessLevel::LocalDryRunReady, $report->readyLevel);
        self::assertSame([], $report->blockingErrors);
        self::assertTrue($report->privateReadConnectivity);
        self::assertTrue($report->accountReadable);
        self::assertTrue($report->permissionsRead);
        self::assertFalse($report->permissionsTrade);
        self::assertTrue($report->stopLossCapability);
        self::assertFalse($report->killSwitch);
        self::assertContains('hyperliquid_agent_wallet_trade_permission_not_proven', $report->warnings);
        self::assertContains('demo_testnet_write_not_enabled', $report->warnings);
    }

    public function testRuntimeCheckReachesDemoTestnetCandidateWithAllPrerequisites(): void
    {
        $report = (new HyperliquidRuntimeCheck())->check(new ExchangeReadinessInput(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            environment: 'testnet',
            publicConnectivity: true,
            privateReadConnectivity: true,
            privateObservabilityStatus: new ExchangePrivateObservabilityStatus(
                exchange: Exchange::HYPERLIQUID,
                environment: 'testnet',
                privateWsSupported: false,
                privateWsConnected: false,
                privateWsAuthenticated: false,
                ordersStreamReady: false,
                fillsStreamReady: false,
                positionsStreamReady: false,
                initialSnapshotLoaded: true,
                reconciliationFresh: true,
                warnings: ['hyperliquid_polling_private_observability'],
            ),
            instrumentsLoaded: true,
            metadataValid: true,
            precisionValid: true,
            accountReadable: true,
            permissionsRead: true,
            permissionsTrade: true,
            signerConfigured: true,
            signerMatchesAccount: true,
            nonceStoreReady: true,
            collateralReadable: true,
            pollingReady: true,
            mainnetWriteGuard: true,
            demoTestnetWriteGuard: true,
            demoTestnetWriteEnabled: true,
            stopLossCapability: true,
            killSwitch: true,
            dryRun: true,
            allowedMarkets: ['perpetual'],
            maxNotional: 25.0,
        ));

        self::assertSame(ExchangeReadinessLevel::DemoTestnetCandidate, $report->readyLevel);
        self::assertSame([], $report->blockingErrors);
        self::assertFalse($report->permissionsTrade);
        self::assertTrue($report->stopLossCapability);
        self::assertTrue($report->killSwitch);
        self::assertContains('hyperliquid_agent_wallet_trade_permission_not_proven', $report->warnings);
        self::assertNotContains('demo_testnet_write_not_enabled', $report->warnings);
        self::assertNotContains('private_observability_status_missing', $report->warnings);
    }

    public function testRuntimeCheckStaysAccountReadOnlyWithoutNonceStore(): void
    {
        $report = (new HyperliquidRuntimeCheck())->check(new ExchangeReadinessInput(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            environment: 'testnet',
            publicConnectivity: true,
            privateReadConnectivity: true,
            instrumentsLoaded: true,
            metadataValid: true,
            precisionValid: true,
            accountReadable: true,
            permissionsRead: true,
            permissionsTrade: true,
            signerConfigured: true,
            signerMatchesAccount: true,
            nonceStoreReady: false,
            collateralReadable: true,
            pollingReady: true,
            mainnetWriteGuard: true,
            demoTestnetWriteGuard: true,
            demoTestnetWriteEnabled: true,
            stopLossCapability: true,
            killSwitch: true,
            dryRun: true,
            allowedMarkets: ['perpetual'],
            maxNotional: 25.0,
        ));

        self::assertSame(ExchangeReadinessLevel::PrivateReadOnly, $report->readyLevel);
        self::assertSame([], $report->blockingErrors);
        self::assertContains('hyperliquid_nonce_store_not_ready', $report->warnings);
        self::assertContains('local_dry_run_prerequisites_missing', $report->warnings);
    }

    public function testRuntimeCheckPreservesMainnetGuardFailure(): void
    {
        $report = (new HyperliquidRuntimeCheck())->check(new ExchangeReadinessInput(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            environment: 'mainnet',
            mainnetWriteGuard: false,
            demoTestnetWriteGuard: true,
            dryRun: false,
        ));

        self::assertContains('mainnet_write_guard_missing', $report->blockingErrors);
        self::assertContains('hyperliquid_provider_bundle_skeleton_not_ready', $report->blockingErrors);
    }

    public function testSignerAndNonceContractsArePresent(): void
    {
        self::assertTrue(interface_exists(HyperliquidSignerInterface::class));
        self::assertTrue(interface_exists(HyperliquidNonceManagerInterface::class));
    }

    private function hyperliquidBundle(): ExchangeProviderBundle
    {
        return new ExchangeProviderBundle(
            new ExchangeContext(Exchange::HYPERLIQUID, MarketType::PERPETUAL),
            new HyperliquidMarketGateway(),
            new HyperliquidMetadataProvider(),
            new HyperliquidExecutionGateway(),
            new HyperliquidAccountGateway(),
            new HyperliquidSystemProvider(),
        );
    }

    private function bitmartBundle(): ExchangeProviderBundle
    {
        return new ExchangeProviderBundle(
            new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL),
            $this->createMock(KlineProviderInterface::class),
            $this->createMock(ContractProviderInterface::class),
            $this->createMock(OrderProviderInterface::class),
            $this->createMock(AccountProviderInterface::class),
            $this->createMock(SystemProviderInterface::class),
        );
    }

    /**
     * @param callable(): mixed $operation
     */
    private function assertNotReady(callable $operation, string $reason): void
    {
        try {
            $operation();
            self::fail('Expected Hyperliquid skeleton operation to fail explicitly.');
        } catch (HyperliquidProviderNotReadyException $exception) {
            self::assertSame($reason, $exception->reason());
            self::assertStringContainsString($reason, $exception->getMessage());
        }
    }
}
