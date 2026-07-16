<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Readiness;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Fake\FakeExchangeFault;
use App\Exchange\Fake\FakeExchangeFaultKind;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOperation;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessInput;
use App\Provider\Fake\FakeRuntimeCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

#[CoversClass(FakeRuntimeCheck::class)]
final class FakeRuntimeCheckTest extends TestCase
{
    public function testInMemoryFakeReportsMissingPaperModelsWithoutClaimingReadiness(): void
    {
        $state = new FakeExchangeStateStore();
        $report = $this->runtimeCheck($state)->current();

        self::assertSame(Exchange::FAKE, $report->exchange);
        self::assertSame(MarketType::PERPETUAL, $report->marketType);
        self::assertSame('fake', $report->environment);
        self::assertSame(ExchangeReadinessLevel::NotReady, $report->readyLevel);
        self::assertFalse($report->publicConnectivity);
        self::assertTrue($report->privateReadConnectivity);
        self::assertFalse($report->permissionsTrade);
        self::assertTrue($report->mainnetWriteGuard);
        self::assertTrue($report->stopLossCapability);
        self::assertNotContains('instruments_not_loaded', $report->blockingErrors);
        self::assertNotContains('metadata_invalid', $report->blockingErrors);
        self::assertNotContains('precision_invalid', $report->blockingErrors);
        self::assertContains('public_connectivity_unavailable', $report->blockingErrors);
        self::assertContains('fake_paper_market_source_not_configured', $report->warnings);
        self::assertContains('fake_paper_persistence_not_configured', $report->warnings);
        self::assertContains('fake_paper_slippage_model_zero', $report->warnings);
    }

    public function testRuntimeMetadataUsesCanonicalCatalogVersions(): void
    {
        $state = new FakeExchangeStateStore();
        $book = new FakeExchangeOrderBook($state);
        $clock = $this->clock();
        $engine = new FakeExchangeMatchingEngine($state, $book, $clock);
        $metadata = (new FakeExchangeAdapter($state, $book, $engine, $clock))->runtimeModelMetadata();

        self::assertSame('fake-instrument-catalog-v1', $metadata['metadata_fixture_version']);
        self::assertSame('brick-math-exact-multiple-v1', $metadata['precision_model_version']);
    }

    public function testPersistentPaperProbesWritableRecoveryWithoutTouchingActiveState(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_runtime_check_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $state->queueFault(new FakeExchangeFault(
                FakeExchangeOperation::PlaceOrder,
                FakeExchangeFaultKind::NetworkTimeout,
            ));
            $stateBeforeCheck = file_get_contents($stateFile);
            self::assertIsString($stateBeforeCheck);
            $health = $state->persistenceHealth();

            self::assertSame([
                'configured' => true,
                'writable' => true,
                'recovery_ready' => true,
            ], $health);

            $report = $this->runtimeCheck($state)->current();
            self::assertSame('fake', $report->environment);
            self::assertNotContains('fake_paper_state_not_writable', $report->blockingErrors);
            self::assertNotContains('fake_paper_state_recovery_not_ready', $report->blockingErrors);
            self::assertNotContains('fake_paper_persistence_not_configured', $report->warnings);
            self::assertCount(0, $state->getOrders());
            self::assertCount(0, $state->events());
            self::assertCount(1, $state->pendingFaults());
            self::assertSame($stateBeforeCheck, file_get_contents($stateFile));
        } finally {
            @unlink($stateFile);
        }
    }

    public function testResidualLocalBookDoesNotProveMarketSourceReadiness(): void
    {
        $state = new FakeExchangeStateStore();
        $state->setOrderBookTop('BTCUSDT', 24999.0, 25001.0);

        $report = $this->runtimeCheck($state)->current();

        self::assertFalse($report->publicConnectivity);
        self::assertContains('public_connectivity_unavailable', $report->blockingErrors);
        self::assertContains('fake_paper_market_source_not_configured', $report->warnings);
        self::assertSame('fake', $report->environment);
    }

    public function testExplicitReadyMarketSourceAndBookEnableMarketDataReadiness(): void
    {
        $state = new FakeExchangeStateStore();
        $state->setOrderBookTop('BTCUSDT', 24999.0, 25001.0);

        $report = $this->runtimeCheck($state, marketDataSourceReady: true)->current();

        self::assertTrue($report->publicConnectivity);
        self::assertNotContains('public_connectivity_unavailable', $report->blockingErrors);
        self::assertNotContains('fake_paper_market_source_not_configured', $report->warnings);
    }

    public function testClockFailureIsReportedWithStableRedactedReason(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                throw new \RuntimeException('clock token=runtime-secret');
            }
        };
        $report = $this->runtimeCheck(new FakeExchangeStateStore(), $clock)->current();

        self::assertContains('fake_paper_clock_not_ready', $report->blockingErrors);
        self::assertStringNotContainsString('runtime-secret', json_encode($report->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testRuntimeClockMustBeExplicitlyControlled(): void
    {
        $report = $this->runtimeCheck(
            new FakeExchangeStateStore(),
            new NativeClock(new \DateTimeZone('UTC')),
            controlledClock: false,
        )->current();

        self::assertContains('fake_paper_clock_not_controlled', $report->blockingErrors);
    }

    public function testRuntimeContractForcesDryRunAndNeverGrantsTradePermission(): void
    {
        $check = $this->runtimeCheck(new FakeExchangeStateStore());
        $report = $check->check(new ExchangeReadinessInput(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            environment: 'paper',
            publicConnectivity: true,
            privateReadConnectivity: true,
            privateObservability: true,
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
            allowedMarkets: ['perpetual'],
            maxNotional: 1.0,
        ));

        self::assertSame(ExchangeReadinessLevel::LocalDryRunReady, $report->readyLevel);
        self::assertFalse($report->permissionsTrade);
        self::assertTrue($report->killSwitch);
    }

    private function runtimeCheck(
        FakeExchangeStateStore $state,
        ?ClockInterface $clock = null,
        bool $controlledClock = true,
        bool $marketDataSourceReady = false,
    ): FakeRuntimeCheck
    {
        $book = new FakeExchangeOrderBook($state);
        $clock ??= $this->clock();
        $engine = new FakeExchangeMatchingEngine($state, $book, $clock);

        return new FakeRuntimeCheck(
            new FakeExchangeAdapter($state, $book, $engine, $clock),
            $state,
            $clock,
            controlledClock: $controlledClock,
            marketDataSourceReady: $marketDataSourceReady,
        );
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
    }
}
