<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry\Execution;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Config\TradeEntryConfigProvider;
use App\Config\TradeEntryConfigResolver;
use App\Config\TradeEntryModeContext;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\CancelOrderResult;
use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\ExchangeReconciliationResult;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\PlaceOrderResult;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeScenarioService;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Registry\ExchangeAdapterRegistry;
use App\Provider\Context\ExchangeContext;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Execution\EmergencyCloseService;
use App\TradeEntry\Execution\ExecutionBox;
use App\TradeEntry\Execution\ExchangeExecutionService;
use App\TradeEntry\Execution\ProtectionEnforcer;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Policy\IdempotencyPolicy;
use App\TradeEntry\Policy\OrderModePolicyInterface;
use App\TradeEntry\Service\TradeEntryMetricsService;
use App\TradeEntry\Types\Side;
use App\TradeEntry\Workflow\ExecuteOrderPlan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(ExchangeExecutionService::class)]
#[CoversClass(ProtectionEnforcer::class)]
#[CoversClass(EmergencyCloseService::class)]
final class ExchangeExecutionServiceTest extends TestCase
{
    private FakeExchangeAdapter $adapter;
    private FakeExchangeScenarioService $scenario;
    private TradeEntryMetricsService $metrics;

    protected function setUp(): void
    {
        $state = new FakeExchangeStateStore();
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine($state, $book, $this->fixedClock());

        $this->adapter = new FakeExchangeAdapter($state, $book, $engine, $this->fixedClock());
        $this->scenario = new FakeExchangeScenarioService($state, $book, $engine);
        $this->metrics = new TradeEntryMetricsService();
    }

    public function testMarketFillWithAttachedStopLossReturnsSubmittedProtected(): void
    {
        $result = $this->service()->execute($this->plan(orderType: 'market'), 'decision-1');

        self::assertSame(ExecutionResult::STATUS_SUBMITTED_PROTECTED, $result->status);
        self::assertTrue($result->raw['protection']['protected']);
        self::assertSame('attached', $result->raw['protection']['protection_mode']);
        self::assertCount(1, $this->adapter->getOpenPositions('BTCUSDT'));

        $stopOrders = $this->stopLossOrders($this->adapter);
        self::assertCount(1, $stopOrders);
        self::assertTrue($stopOrders[0]->reduceOnly);
        self::assertEqualsWithDelta(24800.0, $stopOrders[0]->stopPrice, 0.000001);
    }

    public function testBitmartMarketEntryWithoutSeparateProtectionIsRejectedBeforeSubmit(): void
    {
        $adapter = new BitmartMarketRejectAdapter();
        $plan = $this->plan(
            orderType: 'market',
            exchangeContext: new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL),
        );

        $result = $this->service($adapter)->execute($plan, 'decision-bitmart-market');

        self::assertSame(ExecutionResult::STATUS_ERROR, $result->status);
        self::assertSame('bitmart_market_entry_without_protection_path', $result->raw['reason']);
        self::assertSame(0, $adapter->placeOrderCalls);
    }

    public function testZeroSizeApiFirstPlanIsSkippedBeforeSubmit(): void
    {
        $result = $this->service()->execute($this->plan(orderType: 'limit', size: 0), 'decision-size-zero');

        self::assertSame(ExecutionResult::STATUS_SKIPPED, $result->status);
        self::assertSame('size_below_min', $result->raw['reason']);
        self::assertSame(0, $result->raw['size']);
    }

    public function testMarketFillWithRejectedAttachedStopLossEmergencyClosesPosition(): void
    {
        $this->scenario->rejectNextProtectionOrder();

        $result = $this->service()->execute($this->plan(orderType: 'market'), 'decision-2');

        self::assertSame(ExecutionResult::STATUS_FAILED_UNPROTECTED_CLOSED, $result->status);
        self::assertFalse($result->raw['protection']['protected']);
        self::assertSame('attached_stop_loss_not_confirmed', $result->raw['protection']['reason']);
        self::assertNotNull($result->raw['protection']['emergency_order_id']);
        self::assertCount(0, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertSame(1, $this->metrics->snapshot()['protection_failed']);
        self::assertSame(1, $this->metrics->snapshot()['emergency_close']);
    }

    public function testMarketFillWithRejectedStopLossBecomesCriticalWhenEmergencyCloseFails(): void
    {
        $this->scenario->rejectNextProtectionOrder();
        $adapter = new CapabilityOverrideAdapter(
            $this->adapter,
            $this->adapter->capabilities(),
            rejectEmergencyClose: true,
        );

        $result = $this->service($adapter)->execute($this->plan(orderType: 'market'), 'decision-3');

        self::assertSame(ExecutionResult::STATUS_CRITICAL_UNPROTECTED_POSITION, $result->status);
        self::assertFalse($result->raw['protection']['protected']);
        self::assertSame('critical_unprotected_position', $result->raw['protection']['emergency_close']['reason']);
        self::assertCount(1, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertSame(1, $this->metrics->snapshot()['critical_unprotected_position']);
    }

    public function testExchangeWithoutAttachedStopLossUsesSeparateReduceOnlyStop(): void
    {
        $adapter = new CapabilityOverrideAdapter(
            $this->adapter,
            new ExchangeCapabilities(
                supportsTestnet: true,
                supportsWebSocketPrivate: true,
                supportsClientOrderId: true,
                supportsCancelByClientOrderId: true,
                supportsPostOnly: true,
                supportsIoc: true,
                supportsReduceOnly: true,
                supportsAttachedStopLossOnEntry: false,
                supportsAttachedTakeProfitOnEntry: false,
                supportsTriggerOrders: true,
                supportsModifyOrder: false,
                requiresSeparateLeverageSubmit: false,
                supportsPerSymbolLeverage: true,
            ),
        );

        $result = $this->service($adapter)->execute($this->plan(orderType: 'market'), 'decision-4');

        self::assertSame(ExecutionResult::STATUS_SUBMITTED_PROTECTED, $result->status);
        self::assertSame('separate_reduce_only_stop', $result->raw['protection']['protection_mode']);

        $stopOrders = $this->stopLossOrders($this->adapter);
        self::assertCount(1, $stopOrders);
        self::assertTrue($stopOrders[0]->reduceOnly);
        self::assertEqualsWithDelta(1.0, $stopOrders[0]->remainingQuantity, 0.000001);
    }

    public function testCrossingLimitWithAttachedStopLossIsConfirmedBeforeProtectedStatus(): void
    {
        $result = $this->service()->execute($this->plan(orderType: 'limit', entry: 26000.0), 'decision-5');

        self::assertSame(ExecutionResult::STATUS_SUBMITTED_PROTECTED, $result->status);
        self::assertSame(ExchangeOrderStatus::FILLED->value, $result->raw['order']['status']);
        self::assertCount(1, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertCount(1, $this->stopLossOrders($this->adapter));
    }

    public function testExpiredEntryDoesNotReturnEntrySubmitted(): void
    {
        $result = $this->service()->execute($this->plan(orderType: 'limit', entry: 24950.0, orderMode: 3), 'decision-6');

        self::assertSame(ExecutionResult::STATUS_ERROR, $result->status);
        self::assertSame('entry_closed_without_fill', $result->raw['reason']);
        self::assertSame(ExchangeOrderStatus::EXPIRED->value, $result->raw['order']['status']);
        self::assertCount(0, $this->adapter->getOpenPositions('BTCUSDT'));
    }

    public function testOpenEntryIsCancelledInsteadOfReturnedPendingWithoutWatcher(): void
    {
        $result = $this->service()->execute($this->plan(orderType: 'limit', entry: 24950.0), 'decision-7');

        self::assertSame(ExecutionResult::STATUS_ERROR, $result->status);
        self::assertSame('entry_pending_cancelled_without_fill', $result->raw['reason']);
        self::assertTrue($result->raw['cancel']['cancelled']);
        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
        self::assertCount(0, $this->adapter->getOpenPositions('BTCUSDT'));
    }

    public function testFillDuringPendingCancelRaceEmergencyClosesPosition(): void
    {
        $adapter = new FillDuringCancelAdapter($this->adapter);

        $result = $this->service($adapter)->execute($this->plan(orderType: 'limit', entry: 24950.0), 'decision-cancel-race');

        self::assertSame(ExecutionResult::STATUS_FAILED_UNPROTECTED_CLOSED, $result->status);
        self::assertSame('entry_filled_during_cancel_race', $result->raw['protection']['reason']);
        self::assertTrue($result->raw['protection']['cancel']['filled_after_cancel']);
        self::assertCount(0, $this->adapter->getOpenPositions('BTCUSDT'));
    }

    public function testPartiallyFilledEntryCancelsRemainderBeforeProtection(): void
    {
        $adapter = new PartialFillOnEntryAdapter($this->adapter);

        $result = $this->service($adapter)->execute($this->plan(orderType: 'limit', entry: 24950.0), 'decision-8');

        self::assertSame(ExecutionResult::STATUS_SUBMITTED_PROTECTED, $result->status);
        self::assertSame(ExchangeOrderStatus::PARTIALLY_FILLED->value, $result->raw['order']['status']);
        self::assertCount(1, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertEqualsWithDelta(0.4, $this->adapter->getOpenPositions('BTCUSDT')[0]->size, 0.000001);

        $stopOrders = $this->stopLossOrders($this->adapter);
        self::assertCount(1, $stopOrders);
        self::assertEqualsWithDelta(0.4, $stopOrders[0]->remainingQuantity, 0.000001);
    }

    public function testPartialFillCancelVerificationFailureReturnsCriticalAfterEmergencyClose(): void
    {
        $adapter = new CancelVerificationFailureAdapter(new PartialFillOnEntryAdapter($this->adapter));

        $result = $this->service($adapter)->execute($this->plan(orderType: 'limit', entry: 24950.0), 'decision-8b');

        self::assertSame(ExecutionResult::STATUS_CRITICAL_UNPROTECTED_POSITION, $result->status);
        self::assertSame('partial_entry_remainder_cancel_failed', $result->raw['protection']['reason']);
        self::assertTrue($result->raw['protection']['residual_entry_risk']);
        self::assertSame('entry_cancel_verification_exception', $result->raw['protection']['cancel']['reason']);
        self::assertCount(0, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertNotEmpty($this->adapter->getOpenOrders('BTCUSDT'));
    }

    public function testConfirmationFailureEmergencyClosesFilledPosition(): void
    {
        $adapter = new ThrowingOpenOrdersAdapter($this->adapter);

        $result = $this->service($adapter)->execute($this->plan(orderType: 'market'), 'decision-9');

        self::assertSame(ExecutionResult::STATUS_FAILED_UNPROTECTED_CLOSED, $result->status);
        self::assertSame('protection_confirmation_failed', $result->raw['protection']['reason']);
        self::assertCount(0, $this->adapter->getOpenPositions('BTCUSDT'));
    }

    public function testConfirmationFailureCancelsStaleStopAfterEmergencyClose(): void
    {
        $adapter = new FlakyOpenOrdersAdapter($this->adapter);

        $result = $this->service($adapter)->execute($this->plan(orderType: 'market'), 'decision-stale-stop');

        self::assertSame(ExecutionResult::STATUS_FAILED_UNPROTECTED_CLOSED, $result->status);
        self::assertSame('protection_confirmation_failed', $result->raw['protection']['reason']);
        self::assertSame(2, $result->raw['protection']['stale_protection_cancel']['cancelled']);
        self::assertCount(0, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
    }

    public function testPendingEmergencyCloseWithNoRemainingPositionIsSuccessful(): void
    {
        $this->scenario->rejectNextProtectionOrder();
        $adapter = new PendingEmergencyCloseAdapter($this->adapter);

        $result = $this->service($adapter)->execute($this->plan(orderType: 'market'), 'decision-pending-close');

        self::assertSame(ExecutionResult::STATUS_FAILED_UNPROTECTED_CLOSED, $result->status);
        self::assertSame('pending', $result->raw['protection']['emergency_close']['close_status']);
        self::assertCount(0, $this->adapter->getOpenPositions('BTCUSDT'));
    }

    public function testEmergencyCloseReturnsCriticalWhenPositionLookupFails(): void
    {
        $adapter = new ThrowingPositionsAdapter($this->adapter);

        $result = $this->service($adapter)->execute($this->plan(orderType: 'market'), 'decision-lookup-failure');

        self::assertSame(ExecutionResult::STATUS_CRITICAL_UNPROTECTED_POSITION, $result->status);
        self::assertSame('emergency_close_position_lookup_failed', $result->raw['protection']['emergency_close']['reason']);
        self::assertSame(1, $this->metrics->snapshot()['critical_unprotected_position']);
    }


    public function testPartiallyConsumedStopDoesNotConfirmCurrentPositionCoverage(): void
    {
        $this->adapter->placeOrder(new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: \App\Exchange\Enum\ExchangeOrderSide::BUY,
            positionSide: \App\Exchange\Enum\ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: \App\Exchange\Enum\ExchangeTimeInForce::IOC,
            quantity: 1.0,
            price: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'existing-entry',
            attachedStopLossPrice: 24800.0,
        ));
        $stop = $this->stopLossOrders($this->adapter)[0];
        $this->scenario->fillOrder($stop->exchangeOrderId, 0.6, 24800.0);

        $result = $this->service()->execute($this->plan(orderType: 'market'), 'decision-10');

        self::assertSame(ExecutionResult::STATUS_SUBMITTED_PROTECTED, $result->status);
        self::assertNotSame($stop->exchangeOrderId, $result->raw['protection']['protection_order_id']);
        self::assertEqualsWithDelta(1.4, $this->adapter->getOpenPositions('BTCUSDT')[0]->size, 0.000001);
        self::assertEqualsWithDelta(1.4, array_sum(array_map(
            static fn (ExchangeOrderDto $order): float => $order->remainingQuantity,
            $this->stopLossOrders($this->adapter),
        )), 0.000001);
    }

    public function testExplicitBitmartContextUsesApiFirstExecution(): void
    {
        $execution = (new \ReflectionClass(ExecutionBox::class))->newInstanceWithoutConstructor();
        $exchangeExecution = (new \ReflectionClass(ExchangeExecutionService::class))->newInstanceWithoutConstructor();
        $workflow = new ExecuteOrderPlan($execution, $exchangeExecution, new NullLogger());
        $method = new \ReflectionMethod(ExecuteOrderPlan::class, 'shouldUseApiFirstExecution');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($workflow, $this->plan(
            orderType: 'limit',
            exchangeContext: null,
            useDefaultExchangeContext: false,
        )));
        self::assertTrue($method->invoke($workflow, $this->plan(
            orderType: 'limit',
            exchangeContext: new ExchangeContext(Exchange::BITMART, MarketType::PERPETUAL),
        )));
    }

    private function service(?ExchangeAdapterInterface $adapter = null): ExchangeExecutionService
    {
        $logger = new NullLogger();
        $emergencyClose = new EmergencyCloseService($this->metrics, $logger);
        $enforcer = new ProtectionEnforcer($emergencyClose, $this->metrics, $logger);

        return new ExchangeExecutionService(
            new ExchangeAdapterRegistry([$adapter ?? $this->adapter]),
            $enforcer,
            new IdempotencyPolicy(),
            new class implements OrderModePolicyInterface {
                public function enforce(OrderPlanModel $plan): void
                {
                }
            },
            $this->configResolver(),
            $logger,
        );
    }

    private function configResolver(): TradeEntryConfigResolver
    {
        $projectDir = sys_get_temp_dir() . '/trade_entry_api04_' . bin2hex(random_bytes(4));
        $configDir = $projectDir . '/config/app';
        mkdir($configDir, 0777, true);
        file_put_contents($configDir . '/trade_entry.unit.yaml', <<<YAML
trade_entry:
  defaults:
    initial_margin_usdt: 100.0
  leverage: {}
YAML);

        $provider = new TradeEntryConfigProvider(new ParameterBag([
            'kernel.project_dir' => $projectDir,
            'mode' => [],
        ]));

        return new TradeEntryConfigResolver(
            provider: $provider,
            modeContext: new TradeEntryModeContext($provider, 'unit', new NullLogger()),
            logger: new NullLogger(),
        );
    }

    private function plan(
        string $orderType,
        float $entry = 25000.0,
        int $orderMode = 1,
        ?ExchangeContext $exchangeContext = null,
        int $size = 1,
        int $leverage = 3,
        bool $useDefaultExchangeContext = true,
    ): OrderPlanModel
    {
        return new OrderPlanModel(
            symbol: 'BTCUSDT',
            side: Side::Long,
            orderType: $orderType,
            openType: 'isolated',
            orderMode: $orderMode,
            entry: $entry,
            stop: 24800.0,
            takeProfit: 25200.0,
            size: $size,
            leverage: $leverage,
            pricePrecision: 2,
            contractSize: 1.0,
            exchangeContext: $useDefaultExchangeContext
                ? ($exchangeContext ?? new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL))
                : $exchangeContext,
        );
    }

    /**
     * @return ExchangeOrderDto[]
     */
    private function stopLossOrders(ExchangeAdapterInterface $adapter): array
    {
        return array_values(array_filter(
            $adapter->getOpenOrders('BTCUSDT'),
            static fn (ExchangeOrderDto $order): bool => $order->orderType === ExchangeOrderType::STOP_LOSS,
        ));
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
            }
        };
    }
}

final readonly class CapabilityOverrideAdapter implements ExchangeAdapterInterface
{
    public function __construct(
        private ExchangeAdapterInterface $inner,
        private ExchangeCapabilities $capabilities,
        private bool $rejectEmergencyClose = false,
    ) {
    }

    public function exchange(): Exchange
    {
        return $this->inner->exchange();
    }

    public function marketType(): MarketType
    {
        return $this->inner->marketType();
    }

    public function capabilities(): ExchangeCapabilities
    {
        return $this->capabilities;
    }

    /**
     * @return ExchangeBalanceDto[]
     */
    public function getBalances(): array
    {
        return $this->inner->getBalances();
    }

    /**
     * @return ExchangePositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        return $this->inner->getOpenPositions($symbol);
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        return $this->inner->getOpenOrders($symbol);
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        if ($this->rejectEmergencyClose && $request->reduceOnly && $request->orderType === ExchangeOrderType::MARKET) {
            return new PlaceOrderResult(
                accepted: false,
                symbol: $request->symbol,
                clientOrderId: $request->clientOrderId,
                exchangeOrderId: null,
                status: ExchangeOrderStatus::REJECTED,
                submittedAt: new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
                metadata: ['reason' => 'forced_emergency_close_rejection'],
            );
        }

        return $this->inner->placeOrder($request);
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        return $this->inner->cancelOrder($request);
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        return $this->inner->getOrder($symbol, $exchangeOrderId);
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return $this->inner->getOrderBookTop($symbol);
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        return $this->inner->setLeverage($symbol, $leverage, $marginMode);
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        return $this->inner->reconcile($symbol);
    }
}

final readonly class PartialFillOnEntryAdapter implements ExchangeAdapterInterface
{
    public function __construct(private ExchangeAdapterInterface $inner)
    {
    }

    public function exchange(): Exchange
    {
        return $this->inner->exchange();
    }

    public function marketType(): MarketType
    {
        return $this->inner->marketType();
    }

    public function capabilities(): ExchangeCapabilities
    {
        return new ExchangeCapabilities(
            supportsTestnet: true,
            supportsWebSocketPrivate: true,
            supportsClientOrderId: true,
            supportsCancelByClientOrderId: true,
            supportsPostOnly: true,
            supportsIoc: true,
            supportsReduceOnly: true,
            supportsAttachedStopLossOnEntry: false,
            supportsAttachedTakeProfitOnEntry: false,
            supportsTriggerOrders: true,
            supportsModifyOrder: false,
            requiresSeparateLeverageSubmit: false,
            supportsPerSymbolLeverage: true,
        );
    }

    /**
     * @return ExchangeBalanceDto[]
     */
    public function getBalances(): array
    {
        return $this->inner->getBalances();
    }

    /**
     * @return ExchangePositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        return $this->inner->getOpenPositions($symbol);
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        return $this->inner->getOpenOrders($symbol);
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        $placed = $this->inner->placeOrder($request);
        if (!$request->reduceOnly && $request->orderType === ExchangeOrderType::LIMIT && $placed->exchangeOrderId !== null) {
            $engine = $this->inner;
            if ($engine instanceof FakeExchangeAdapter) {
                $state = (new \ReflectionProperty(FakeExchangeAdapter::class, 'matchingEngine'));
                $state->setAccessible(true);
                /** @var FakeExchangeMatchingEngine $matching */
                $matching = $state->getValue($engine);
                $order = $matching->fillOrder($placed->exchangeOrderId, 0.4);

                return new PlaceOrderResult(
                    accepted: true,
                    symbol: $placed->symbol,
                    clientOrderId: $placed->clientOrderId,
                    exchangeOrderId: $placed->exchangeOrderId,
                    status: $order?->status ?? $placed->status,
                    submittedAt: $placed->submittedAt,
                    order: $order,
                    metadata: $placed->metadata,
                );
            }
        }

        return $placed;
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        return $this->inner->cancelOrder($request);
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        return $this->inner->getOrder($symbol, $exchangeOrderId);
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return $this->inner->getOrderBookTop($symbol);
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        return $this->inner->setLeverage($symbol, $leverage, $marginMode);
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        return $this->inner->reconcile($symbol);
    }
}

final readonly class FillDuringCancelAdapter implements ExchangeAdapterInterface
{
    public function __construct(private ExchangeAdapterInterface $inner)
    {
    }

    public function exchange(): Exchange
    {
        return $this->inner->exchange();
    }

    public function marketType(): MarketType
    {
        return $this->inner->marketType();
    }

    public function capabilities(): ExchangeCapabilities
    {
        return $this->inner->capabilities();
    }

    /**
     * @return ExchangeBalanceDto[]
     */
    public function getBalances(): array
    {
        return $this->inner->getBalances();
    }

    /**
     * @return ExchangePositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        return $this->inner->getOpenPositions($symbol);
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        return $this->inner->getOpenOrders($symbol);
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        return $this->inner->placeOrder($request);
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        if ($this->inner instanceof FakeExchangeAdapter && $request->exchangeOrderId !== null) {
            $state = (new \ReflectionProperty(FakeExchangeAdapter::class, 'matchingEngine'));
            $state->setAccessible(true);
            /** @var FakeExchangeMatchingEngine $matching */
            $matching = $state->getValue($this->inner);
            $matching->fillOrder($request->exchangeOrderId, 0.4);
        }

        return $this->inner->cancelOrder($request);
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        return $this->inner->getOrder($symbol, $exchangeOrderId);
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return $this->inner->getOrderBookTop($symbol);
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        return $this->inner->setLeverage($symbol, $leverage, $marginMode);
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        return $this->inner->reconcile($symbol);
    }
}

final readonly class PendingEmergencyCloseAdapter implements ExchangeAdapterInterface
{
    public function __construct(private ExchangeAdapterInterface $inner)
    {
    }

    public function exchange(): Exchange
    {
        return $this->inner->exchange();
    }

    public function marketType(): MarketType
    {
        return $this->inner->marketType();
    }

    public function capabilities(): ExchangeCapabilities
    {
        return $this->inner->capabilities();
    }

    /**
     * @return ExchangeBalanceDto[]
     */
    public function getBalances(): array
    {
        return $this->inner->getBalances();
    }

    /**
     * @return ExchangePositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        return $this->inner->getOpenPositions($symbol);
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        return $this->inner->getOpenOrders($symbol);
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        $placed = $this->inner->placeOrder($request);
        if ($request->reduceOnly && $request->orderType === ExchangeOrderType::MARKET) {
            return new PlaceOrderResult(
                accepted: true,
                symbol: $placed->symbol,
                clientOrderId: $placed->clientOrderId,
                exchangeOrderId: $placed->exchangeOrderId,
                status: ExchangeOrderStatus::PENDING,
                submittedAt: $placed->submittedAt,
                order: $placed->order,
                metadata: $placed->metadata,
            );
        }

        return $placed;
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        return $this->inner->cancelOrder($request);
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        return $this->inner->getOrder($symbol, $exchangeOrderId);
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return $this->inner->getOrderBookTop($symbol);
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        return $this->inner->setLeverage($symbol, $leverage, $marginMode);
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        return $this->inner->reconcile($symbol);
    }
}

final readonly class ThrowingOpenOrdersAdapter implements ExchangeAdapterInterface
{
    public function __construct(private ExchangeAdapterInterface $inner)
    {
    }

    public function exchange(): Exchange
    {
        return $this->inner->exchange();
    }

    public function marketType(): MarketType
    {
        return $this->inner->marketType();
    }

    public function capabilities(): ExchangeCapabilities
    {
        return $this->inner->capabilities();
    }

    /**
     * @return ExchangeBalanceDto[]
     */
    public function getBalances(): array
    {
        return $this->inner->getBalances();
    }

    /**
     * @return ExchangePositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        return $this->inner->getOpenPositions($symbol);
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        throw new \RuntimeException('open orders unavailable');
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        return $this->inner->placeOrder($request);
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        return $this->inner->cancelOrder($request);
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        return $this->inner->getOrder($symbol, $exchangeOrderId);
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return $this->inner->getOrderBookTop($symbol);
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        return $this->inner->setLeverage($symbol, $leverage, $marginMode);
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        return $this->inner->reconcile($symbol);
    }
}

final class FlakyOpenOrdersAdapter implements ExchangeAdapterInterface
{
    private int $openOrderCalls = 0;

    public function __construct(private readonly ExchangeAdapterInterface $inner)
    {
    }

    public function exchange(): Exchange
    {
        return $this->inner->exchange();
    }

    public function marketType(): MarketType
    {
        return $this->inner->marketType();
    }

    public function capabilities(): ExchangeCapabilities
    {
        return $this->inner->capabilities();
    }

    /**
     * @return ExchangeBalanceDto[]
     */
    public function getBalances(): array
    {
        return $this->inner->getBalances();
    }

    /**
     * @return ExchangePositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        return $this->inner->getOpenPositions($symbol);
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        if ($this->openOrderCalls++ === 0) {
            throw new \RuntimeException('open orders temporarily unavailable');
        }

        return $this->inner->getOpenOrders($symbol);
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        return $this->inner->placeOrder($request);
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        return $this->inner->cancelOrder($request);
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        return $this->inner->getOrder($symbol, $exchangeOrderId);
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return $this->inner->getOrderBookTop($symbol);
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        return $this->inner->setLeverage($symbol, $leverage, $marginMode);
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        return $this->inner->reconcile($symbol);
    }
}

final readonly class ThrowingPositionsAdapter implements ExchangeAdapterInterface
{
    public function __construct(private ExchangeAdapterInterface $inner)
    {
    }

    public function exchange(): Exchange
    {
        return $this->inner->exchange();
    }

    public function marketType(): MarketType
    {
        return $this->inner->marketType();
    }

    public function capabilities(): ExchangeCapabilities
    {
        return $this->inner->capabilities();
    }

    /**
     * @return ExchangeBalanceDto[]
     */
    public function getBalances(): array
    {
        return $this->inner->getBalances();
    }

    /**
     * @return ExchangePositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        throw new \RuntimeException('positions unavailable');
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        return $this->inner->getOpenOrders($symbol);
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        return $this->inner->placeOrder($request);
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        return $this->inner->cancelOrder($request);
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        return $this->inner->getOrder($symbol, $exchangeOrderId);
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return $this->inner->getOrderBookTop($symbol);
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        return $this->inner->setLeverage($symbol, $leverage, $marginMode);
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        return $this->inner->reconcile($symbol);
    }
}

final readonly class CancelVerificationFailureAdapter implements ExchangeAdapterInterface
{
    public function __construct(private ExchangeAdapterInterface $inner)
    {
    }

    public function exchange(): Exchange
    {
        return $this->inner->exchange();
    }

    public function marketType(): MarketType
    {
        return $this->inner->marketType();
    }

    public function capabilities(): ExchangeCapabilities
    {
        return $this->inner->capabilities();
    }

    /**
     * @return ExchangeBalanceDto[]
     */
    public function getBalances(): array
    {
        return $this->inner->getBalances();
    }

    /**
     * @return ExchangePositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        return $this->inner->getOpenPositions($symbol);
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        return $this->inner->getOpenOrders($symbol);
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        return $this->inner->placeOrder($request);
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        return new CancelOrderResult(
            cancelled: false,
            symbol: strtoupper($request->symbol),
            exchangeOrderId: $request->exchangeOrderId,
            clientOrderId: $request->clientOrderId,
            status: ExchangeOrderStatus::UNKNOWN,
            metadata: ['reason' => 'cancel_status_unconfirmed'],
        );
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        throw new \RuntimeException('order lookup unavailable after cancel');
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return $this->inner->getOrderBookTop($symbol);
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        return $this->inner->setLeverage($symbol, $leverage, $marginMode);
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        return $this->inner->reconcile($symbol);
    }
}

final class BitmartMarketRejectAdapter implements ExchangeAdapterInterface
{
    public int $placeOrderCalls = 0;

    public function exchange(): Exchange
    {
        return Exchange::BITMART;
    }

    public function marketType(): MarketType
    {
        return MarketType::PERPETUAL;
    }

    public function capabilities(): ExchangeCapabilities
    {
        return new ExchangeCapabilities(
            supportsClientOrderId: true,
            supportsIoc: true,
            supportsReduceOnly: true,
            supportsAttachedStopLossOnEntry: true,
            supportsAttachedTakeProfitOnEntry: true,
            supportsTriggerOrders: false,
        );
    }

    /**
     * @return ExchangeBalanceDto[]
     */
    public function getBalances(): array
    {
        return [];
    }

    /**
     * @return ExchangePositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        return [];
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        return [];
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        ++$this->placeOrderCalls;
        throw new \RuntimeException('Bitmart market entry should be rejected before submit');
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        throw new \RuntimeException('cancelOrder should not be called');
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        return null;
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        throw new \RuntimeException('getOrderBookTop should not be called');
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        throw new \RuntimeException('setLeverage should not be called');
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        throw new \RuntimeException('reconcile should not be called');
    }
}
