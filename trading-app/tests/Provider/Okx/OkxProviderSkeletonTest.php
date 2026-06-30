<?php

declare(strict_types=1);

namespace App\Tests\Provider\Okx;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Common\Enum\Timeframe;
use App\Exchange\Readiness\ExchangeReadinessInput;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangePrivateObservabilityStatus;
use App\Provider\Okx\OkxAccountGateway;
use App\Provider\Okx\OkxMarketDataGateway;
use App\Provider\Okx\OkxMetadataProvider;
use App\Provider\Okx\OkxOrderGateway;
use App\Provider\Okx\OkxPositionGateway;
use App\Provider\Okx\OkxProviderNotReadyException;
use App\Provider\Okx\OkxRuntimeCheck;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class OkxProviderSkeletonTest extends TestCase
{
    public function testReadSkeletonMethodsFailExplicitly(): void
    {
        $this->assertNotImplemented(
            static fn (): array => (new OkxMarketDataGateway())->getKlines('BTCUSDT', Timeframe::TF_1M),
            'okx_market_data_not_implemented',
        );

        $this->assertNotImplemented(
            static fn (): array => (new OkxMetadataProvider())->getContracts(),
            'okx_metadata_not_implemented',
        );

        $this->assertNotImplemented(
            static fn (): array => (new OkxAccountGateway())->getOpenPositionsOrFail(),
            'okx_position_not_implemented',
        );

        $this->assertNotImplemented(
            static fn (): array => (new OkxPositionGateway())->getOpenPositionsOrFail(),
            'okx_position_not_implemented',
        );
    }

    public function testOrderSkeletonRejectsEveryMutativeMethodExplicitly(): void
    {
        $orderGateway = new OkxOrderGateway();

        $this->assertNotImplemented(
            static fn () => $orderGateway->placeOrder(
                'BTCUSDT',
                OrderSide::BUY,
                OrderType::LIMIT,
                1.0,
                50000.0,
            ),
            'okx_order_write_not_implemented',
        );

        $this->assertNotImplemented(
            static fn (): bool => $orderGateway->cancelOrder('BTCUSDT', 'ord-1'),
            'okx_order_write_not_implemented',
        );

        $this->assertNotImplemented(
            static fn (): bool => $orderGateway->submitLeverage('BTCUSDT', 2),
            'okx_order_write_not_implemented',
        );
    }

    public function testRuntimeCheckReturnsNotReadyWithoutPublicReadInputs(): void
    {
        $report = (new OkxRuntimeCheck())->check(new ExchangeReadinessInput(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            environment: 'demo',
            mainnetWriteGuard: true,
            dryRun: true,
        ));

        self::assertSame(ExchangeReadinessLevel::NotReady, $report->readyLevel);
        self::assertContains('public_connectivity_unavailable', $report->blockingErrors);
        self::assertSame('okx', $report->toArray()['exchange']);
        self::assertSame('perpetual', $report->toArray()['market_type']);
    }

    public function testRuntimeCheckCanReachPublicReadOnlyForOkx003(): void
    {
        $report = (new OkxRuntimeCheck())->check(new ExchangeReadinessInput(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            environment: 'demo',
            publicConnectivity: true,
            instrumentsLoaded: true,
            metadataValid: true,
            precisionValid: true,
            mainnetWriteGuard: true,
            dryRun: true,
        ));

        self::assertSame(ExchangeReadinessLevel::PublicReadOnly, $report->readyLevel);
        self::assertSame([], $report->blockingErrors);
        self::assertContains('private_read_not_ready', $report->warnings);
    }

    public function testRuntimeCheckCanReachPrivateReadOnlyForOkx004(): void
    {
        $report = (new OkxRuntimeCheck())->check(new ExchangeReadinessInput(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            environment: 'demo',
            publicConnectivity: true,
            privateReadConnectivity: true,
            instrumentsLoaded: true,
            metadataValid: true,
            precisionValid: true,
            accountReadable: true,
            permissionsRead: true,
            mainnetWriteGuard: true,
            dryRun: true,
        ));

        self::assertSame(ExchangeReadinessLevel::PrivateReadOnly, $report->readyLevel);
        self::assertSame([], $report->blockingErrors);
        self::assertNotContains('private_read_not_ready', $report->warnings);
    }

    public function testRuntimeCheckKeepsLocalDryRunReadyWhenDemoTradingIsDisabled(): void
    {
        $report = (new OkxRuntimeCheck())->check($this->okxLocalDryRunInput(
            demoTestnetWriteEnabled: false,
        ));

        self::assertSame(ExchangeReadinessLevel::LocalDryRunReady, $report->readyLevel);
        self::assertTrue($report->stopLossCapability);
        self::assertContains('demo_testnet_write_not_enabled', $report->warnings);
        self::assertNotContains('stop_loss_capability_required_for_demo_testnet_candidate', $report->warnings);
    }

    public function testRuntimeCheckCanReachDemoTestnetCandidateWhenDemoTradingIsExplicitlyEnabled(): void
    {
        $report = (new OkxRuntimeCheck())->check($this->okxLocalDryRunInput(
            demoTestnetWriteEnabled: true,
            killSwitch: true,
        ));

        self::assertSame(ExchangeReadinessLevel::DemoTestnetCandidate, $report->readyLevel);
        self::assertTrue($report->stopLossCapability);
        self::assertTrue($report->killSwitch);
        self::assertNotContains('demo_testnet_write_not_enabled', $report->warnings);
        self::assertNotContains('stop_loss_capability_required_for_demo_testnet_candidate', $report->warnings);
    }

    public function testRuntimeCheckNeverReportsDemoTestnetEnabled(): void
    {
        $report = (new OkxRuntimeCheck())->check($this->okxLocalDryRunInput(
            demoTestnetWriteEnabled: true,
            killSwitch: false,
            dryRun: false,
            permissionsTrade: true,
            privateObservabilityStatus: new ExchangePrivateObservabilityStatus(
                exchange: Exchange::OKX,
                environment: 'demo',
                privateWsSupported: true,
                privateWsConnected: true,
                privateWsAuthenticated: true,
                ordersStreamReady: true,
                fillsStreamReady: true,
                positionsStreamReady: true,
                initialSnapshotLoaded: true,
                lastEventAt: new \DateTimeImmutable('2026-06-29T10:15:00+00:00'),
                reconnecting: false,
                reconciliationFresh: true,
            ),
        ));

        self::assertSame(ExchangeReadinessLevel::DemoTestnetCandidate, $report->readyLevel);
        self::assertNotSame(ExchangeReadinessLevel::DemoTestnetEnabled, $report->readyLevel);
    }

    /**
     * @param callable(): mixed $operation
     */
    private function assertNotImplemented(callable $operation, string $reason): void
    {
        try {
            $operation();
            self::fail('Expected OKX skeleton operation to fail explicitly.');
        } catch (OkxProviderNotReadyException $exception) {
            self::assertSame($reason, $exception->reason());
            self::assertStringContainsString($reason, $exception->getMessage());
        }
    }

    private function okxLocalDryRunInput(
        bool $demoTestnetWriteEnabled,
        bool $killSwitch = true,
        bool $dryRun = true,
        bool $permissionsTrade = false,
        ?ExchangePrivateObservabilityStatus $privateObservabilityStatus = null,
    ): ExchangeReadinessInput {
        return new ExchangeReadinessInput(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            environment: 'demo',
            publicConnectivity: true,
            privateReadConnectivity: true,
            privateObservability: $privateObservabilityStatus !== null,
            privateObservabilityStatus: $privateObservabilityStatus,
            instrumentsLoaded: true,
            metadataValid: true,
            precisionValid: true,
            accountReadable: true,
            permissionsRead: true,
            permissionsTrade: $permissionsTrade,
            mainnetWriteGuard: true,
            demoTestnetWriteGuard: true,
            demoTestnetWriteEnabled: $demoTestnetWriteEnabled,
            stopLossCapability: true,
            killSwitch: $killSwitch,
            dryRun: $dryRun,
            allowedSymbols: ['BTCUSDT'],
            allowedMarkets: ['perpetual'],
            maxNotional: 25.0,
            configHash: str_repeat('b', 64),
        );
    }
}
