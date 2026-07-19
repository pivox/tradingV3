<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Readiness;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Fake\FakeDailyLossCapGuard;
use App\Exchange\Fake\FakeDailyLossCapPolicy;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeFault;
use App\Exchange\Fake\FakeExchangeFaultKind;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOperation;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeLiquidationPolicy;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessInput;
use App\Provider\Fake\FakeRuntimeCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertNotContains('fake_paper_slippage_model_zero', $report->warnings);
        self::assertNotContains('fake_paper_slippage_model_not_ready', $report->blockingErrors);
    }

    public function testRuntimeMetadataUsesCanonicalCatalogVersions(): void
    {
        $state = new FakeExchangeStateStore();
        $book = new FakeExchangeOrderBook($state);
        $clock = $this->clock();
        $engine = new FakeExchangeMatchingEngine(
            $state,
            $book,
            $clock,
            dailyLossCapGuard: new FakeDailyLossCapGuard($state, $clock, new FakeDailyLossCapPolicy('100')),
        );
        $metadata = (new FakeExchangeAdapter($state, $book, $engine, $clock))->runtimeModelMetadata();

        self::assertSame('fake-instrument-catalog-v1', $metadata['metadata_fixture_version']);
        self::assertSame('brick-math-exact-multiple-v1', $metadata['precision_model_version']);
        self::assertSame('fixed_adverse_slippage_bps_v1', $metadata['slippage_model']);
        self::assertSame(5.0, $metadata['slippage_bps']);
        self::assertSame('top_of_book_embedded_spread_v1', $metadata['spread_model']);
        self::assertSame('fake-daily-loss-cap-v1', $metadata['daily_loss_cap_policy_version']);
        self::assertSame('ready', $metadata['daily_loss_cap_status']);
        self::assertSame('100.000000000000', $metadata['daily_loss_cap_limit_usdt']);
        self::assertSame(FakeLiquidationPolicy::MODEL_VERSION, $metadata['liquidation_model_version']);
        self::assertSame('isolated', $metadata['liquidation_supported_margin_mode']);
        self::assertSame('unsupported', $metadata['liquidation_cross_margin_status']);
        self::assertSame('0.010000000000', $metadata['liquidation_guard_buffer_rate']);
        self::assertSame('0.005000000000', $metadata['liquidation_fee_rate']);
        self::assertSame('USDT', $metadata['liquidation_fee_currency']);
        self::assertSame(FakeLiquidationPolicy::FEE_MODEL_VERSION, $metadata['liquidation_fee_model_version']);
        self::assertSame(FakeLiquidationPolicy::MARK_PRICE_SOURCE, $metadata['liquidation_mark_price_source']);
    }

    public function testDailyLossCapInvalidPolicyFailsRuntimeClosedWithStableReason(): void
    {
        $report = $this->runtimeCheck(
            new FakeExchangeStateStore(),
            dailyLossCapUsdt: '0',
        )->current();

        self::assertContains('fake_paper_daily_loss_cap_not_computable', $report->blockingErrors);
    }

    public function testDailyLossCapReachedFailsRuntimeClosed(): void
    {
        $state = new FakeExchangeStateStore();
        $state->appendEvent(new FakeExchangeEvent(
            'order.filled',
            'BTCUSDT',
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            [
                'fill_quantity' => '1',
                'fill_price' => '100',
                'fill_fee' => '0',
                'fee_currency' => 'USDT',
                'spread_cost_usdt' => '0',
                'slippage_cost_usdt' => '0',
                'cost_model_version' => 'fixed_adverse_slippage_bps_v1',
                'spread_model_version' => 'top_of_book_embedded_spread_v1',
                'pnl_source' => 'fake_paper_fill_ledger_v1',
                'cost_completeness' => 'complete',
                'realized_gross_pnl_usdt' => '-100',
                'order_snapshot' => ['reduce_only' => true],
            ],
        ));

        $report = $this->runtimeCheck($state, dailyLossCapUsdt: '100')->current();

        self::assertContains('fake_paper_daily_loss_cap_reached', $report->blockingErrors);
        self::assertNotContains('fake_paper_daily_loss_cap_not_computable', $report->blockingErrors);
    }

    public function testSlippageRuntimeModelValidationFailsClosed(): void
    {
        $valid = [
            'slippage_model' => 'fixed_adverse_slippage_bps_v1',
            'slippage_bps' => 5.0,
            'spread_model' => 'top_of_book_embedded_spread_v1',
        ];

        self::assertTrue(FakeRuntimeCheck::slippageModelReady($valid));
        self::assertFalse(FakeRuntimeCheck::slippageModelReady(array_replace($valid, ['slippage_bps' => 0.0])));
        self::assertFalse(FakeRuntimeCheck::slippageModelReady(array_replace($valid, ['slippage_bps' => -5.0])));
        self::assertFalse(FakeRuntimeCheck::slippageModelReady(array_replace($valid, ['slippage_bps' => 'invalid'])));
        self::assertFalse(FakeRuntimeCheck::slippageModelReady(array_replace($valid, ['slippage_model' => 'unsupported'])));
        self::assertFalse(FakeRuntimeCheck::slippageModelReady(array_replace($valid, ['spread_model' => 'unsupported'])));
        self::assertFalse(FakeRuntimeCheck::slippageModelReady([
            'slippage_model' => 'fixed_adverse_slippage_bps_v1',
        ]));
    }

    public function testLiquidationRuntimeModelValidationFailsClosed(): void
    {
        $valid = [
            'liquidation_model_version' => FakeLiquidationPolicy::MODEL_VERSION,
            'liquidation_supported_margin_mode' => 'isolated',
            'liquidation_cross_margin_status' => 'unsupported',
            'liquidation_guard_buffer_rate' => '0.010000000000',
            'liquidation_fee_rate' => '0.005000000000',
            'liquidation_fee_currency' => 'USDT',
            'liquidation_fee_model_version' => FakeLiquidationPolicy::FEE_MODEL_VERSION,
            'liquidation_mark_price_source' => FakeLiquidationPolicy::MARK_PRICE_SOURCE,
        ];

        self::assertTrue(FakeRuntimeCheck::liquidationModelReady($valid));
        self::assertFalse(FakeRuntimeCheck::liquidationModelReady(array_replace($valid, [
            'liquidation_model_version' => 'unknown',
        ])));
        self::assertFalse(FakeRuntimeCheck::liquidationModelReady(array_replace($valid, [
            'liquidation_supported_margin_mode' => 'cross',
        ])));
        self::assertFalse(FakeRuntimeCheck::liquidationModelReady(array_replace($valid, [
            'liquidation_fee_rate' => null,
        ])));
        self::assertFalse(FakeRuntimeCheck::liquidationModelReady(array_replace($valid, [
            'liquidation_mark_price_source' => 'last-trade',
        ])));
    }

    public function testMissingExplicitMarkFailsRuntimeClosedWithoutBookFallback(): void
    {
        $state = new FakeExchangeStateStore();
        $state->clearMarkPrice('BTCUSDT');
        $state->setOrderBookTop('BTCUSDT', 24999.0, 25001.0);

        $report = $this->runtimeCheck($state, marketDataSourceReady: true)->current();

        self::assertContains('fake_paper_liquidation_mark_not_ready', $report->blockingErrors);
        self::assertTrue($report->publicConnectivity);
    }

    public function testPersistedCrossSettingFailsRuntimeClosed(): void
    {
        $state = new FakeExchangeStateStore();
        $state->setLeverageSetting('BTCUSDT', 10, 'cross');

        $report = $this->runtimeCheck($state)->current();

        self::assertContains('fake_paper_cross_margin_state_unsupported', $report->blockingErrors);
    }

    #[DataProvider('activeOrderStatuses')]
    public function testPersistedActiveCrossMarginOrderFailsRuntimeClosed(ExchangeOrderStatus $status): void
    {
        $state = new FakeExchangeStateStore();
        $state->saveOrder($this->crossMarginOrder($status));

        $report = $this->runtimeCheck($state)->current();

        self::assertContains('fake_paper_cross_margin_state_unsupported', $report->blockingErrors);
    }

    /** @return iterable<string,array{ExchangeOrderStatus}> */
    public static function activeOrderStatuses(): iterable
    {
        yield 'pending' => [ExchangeOrderStatus::PENDING];
        yield 'open' => [ExchangeOrderStatus::OPEN];
        yield 'partially filled' => [ExchangeOrderStatus::PARTIALLY_FILLED];
    }

    #[DataProvider('terminalOrderStatuses')]
    public function testPersistedTerminalCrossMarginOrderDoesNotFailRuntimeClosed(ExchangeOrderStatus $status): void
    {
        $state = new FakeExchangeStateStore();
        $state->saveOrder($this->crossMarginOrder($status));

        $report = $this->runtimeCheck($state)->current();

        self::assertNotContains('fake_paper_cross_margin_state_unsupported', $report->blockingErrors);
    }

    /** @return iterable<string,array{ExchangeOrderStatus}> */
    public static function terminalOrderStatuses(): iterable
    {
        yield 'filled' => [ExchangeOrderStatus::FILLED];
        yield 'cancelled' => [ExchangeOrderStatus::CANCELLED];
        yield 'rejected' => [ExchangeOrderStatus::REJECTED];
        yield 'expired' => [ExchangeOrderStatus::EXPIRED];
    }

    public function testOpenPositionWithoutPersistedLiquidationIdentityFailsRuntimeClosed(): void
    {
        $state = new FakeExchangeStateStore();
        $state->savePosition(new ExchangePositionDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangePositionSide::LONG,
            size: 1.0,
            entryPrice: 25000.0,
            markPrice: 25000.0,
            unrealizedPnl: 0.0,
            realizedPnl: 0.0,
            margin: 2500.0,
            leverage: 10.0,
            openedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            metadata: [
                'liquidation_model_version' => FakeLiquidationPolicy::MODEL_VERSION,
                'liquidation_margin_mode' => 'isolated',
                'liquidation_quantity_decimal' => '1.000000000000',
                'liquidation_entry_price_decimal' => '25000.000000000000',
                'liquidation_isolated_margin_decimal' => '2500.000000000000',
                'liquidation_contract_size_decimal' => '1.000000000000',
                'liquidation_maintenance_margin_rate' => '0.005000000000',
                'liquidation_price_decimal' => '22613.065326633166',
                'liquidation_guard_price_decimal' => '22863.065326633166',
            ],
        ));

        $report = $this->runtimeCheck($state)->current();

        self::assertContains('fake_paper_liquidation_position_state_not_ready', $report->blockingErrors);
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

    public function testMissingPersistentStateUsesInitializedMemoryWithoutCreatingActiveFile(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_runtime_check_initial_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $report = $this->runtimeCheck($state, stateFile: $stateFile)->current();

            self::assertTrue($report->privateReadConnectivity);
            self::assertTrue($report->accountReadable);
            self::assertTrue($report->permissionsRead);
            self::assertNotNull($report->configHash);
            self::assertNotNull($report->configProfile);
            self::assertFileDoesNotExist($stateFile);
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
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
        ?string $stateFile = null,
        string $dailyLossCapUsdt = '100',
    ): FakeRuntimeCheck
    {
        $book = new FakeExchangeOrderBook($state);
        $clock ??= $this->clock();
        $guard = new FakeDailyLossCapGuard($state, $clock, new FakeDailyLossCapPolicy($dailyLossCapUsdt));
        $engine = new FakeExchangeMatchingEngine($state, $book, $clock, dailyLossCapGuard: $guard);

        return new FakeRuntimeCheck(
            new FakeExchangeAdapter($state, $book, $engine, $clock),
            $state,
            $clock,
            controlledClock: $controlledClock,
            marketDataSourceReady: $marketDataSourceReady,
            stateFile: $stateFile,
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

    private function crossMarginOrder(ExchangeOrderStatus $status): ExchangeOrderDto
    {
        $filledQuantity = match ($status) {
            ExchangeOrderStatus::FILLED => 1.0,
            ExchangeOrderStatus::PARTIALLY_FILLED => 0.5,
            default => 0.0,
        };

        return new ExchangeOrderDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'fake-cross-' . $status->value,
            clientOrderId: null,
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            status: $status,
            quantity: 1.0,
            filledQuantity: $filledQuantity,
            remainingQuantity: 1.0 - $filledQuantity,
            price: 24950.0,
            averagePrice: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: true,
            timeInForce: null,
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            metadata: ['margin_mode' => 'cross'],
        );
    }
}
