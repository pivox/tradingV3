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
            'hyperliquid_execution_not_ready',
        );
        $this->assertNotReady(
            static fn (): array => $scoped->getAccountProvider()->getOpenPositionsOrFail('BTCUSDT'),
            'hyperliquid_account_not_ready',
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

    public function testRuntimeCheckCanReachPublicReadOnlyButNeverEnablesTestnet(): void
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
            mainnetWriteGuard: true,
            demoTestnetWriteGuard: true,
            demoTestnetWriteEnabled: true,
            stopLossCapability: true,
            killSwitch: false,
            dryRun: false,
        ));

        self::assertSame(ExchangeReadinessLevel::PublicReadOnly, $report->readyLevel);
        self::assertSame([], $report->blockingErrors);
        self::assertFalse($report->permissionsTrade);
        self::assertFalse($report->stopLossCapability);
        self::assertTrue($report->killSwitch);
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

    public function testSignerAndNonceContractsArePresentButNotImplemented(): void
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
