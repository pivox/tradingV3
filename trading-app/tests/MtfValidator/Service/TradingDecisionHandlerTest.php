<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Common\Enum\SignalSide;
use App\Contract\Indicator\IndicatorProviderInterface;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\MtfValidator\Execution\ExecutionSelector;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Service\TradingDecisionHandler;
use App\Config\{TradeEntryConfig, MtfValidationConfig};
use App\TradeEntry\Dto\EntryZone;
use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\EntryZone\EntryZoneCalculator;
use App\TradeEntry\Service\TradeEntryService;
use App\TradeEntry\Types\Side;
use App\Logging\LifecycleContextFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TradingDecisionHandlerTest extends TestCase
{
    private TradingDecisionHandler $handler;
    /** @var TradeEntryService&MockObject */
    private TradeEntryService $tradeEntryService;
    private AuditLoggerInterface $auditLogger;
    private LoggerInterface $logger;
    private LoggerInterface $positionsFlowLogger;
    /** @var IndicatorProviderInterface&MockObject */
    private IndicatorProviderInterface $indicatorProvider;
    /** @var EntryZoneCalculator&MockObject */
    private EntryZoneCalculator $entryZoneCalculator;
    /** @var ExecutionSelector&MockObject */
    private ExecutionSelector $executionSelector;

    protected function setUp(): void
    {
        $this->tradeEntryService = $this->createMock(TradeEntryService::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->positionsFlowLogger = $this->createMock(LoggerInterface::class);

        $defaults = [
            'risk_pct_percent' => 2.0,
            'initial_margin_usdt' => 100.0,
            'order_type' => 'limit',
            'open_type' => 'isolated',
            'order_mode' => 4,
            'r_multiple' => 2.0,
            'stop_from' => 'atr',
            'atr_k' => 1.5,
            'market_max_spread_pct' => 0.001,
            'timeframe_multipliers' => [
                '1m' => 1.0,
                '5m' => 0.75,
            ],
        ];

        $tradeEntryConfig = $this->createMock(TradeEntryConfig::class);
        $tradeEntryConfig->method('getDecision')->willReturn([
            'allowed_execution_timeframes' => ['1m', '5m', '15m'],
            'require_price_or_atr' => true,
        ]);
        $tradeEntryConfig->method('getDefaults')->willReturn($defaults);
        $tradeEntryConfig->method('getVersion')->willReturn('test');

        $requestBuilder = $this->createMock(\App\TradeEntry\Builder\TradeEntryRequestBuilder::class);
        $requestBuilder->method('fromMtfSignal')->willReturn(
            new TradeEntryRequest(
                symbol: 'BTCUSDT',
                side: \App\TradeEntry\Types\Side::Long,
                orderType: 'limit',
                openType: 'isolated',
                orderMode: 4,
                initialMarginUsdt: 100.0,
                riskPct: 0.02,
                rMultiple: 2.0,
                entryLimitHint: 50250.0,
                stopFrom: 'atr',
                pivotSlPolicy: 'nearest_below',
                pivotSlBufferPct: null,
                pivotSlMinKeepRatio: null,
                atrValue: 35.0,
                atrK: 1.5,
                marketMaxSpreadPct: 0.001,
                insideTicks: 1,
                maxDeviationPct: null,
                implausiblePct: null,
                zoneMaxDeviationPct: null,
                tpPolicy: 'pivot_conservative',
                tpBufferPct: null,
                tpBufferTicks: null,
                tpMinKeepRatio: 0.95,
                tpMaxExtraR: null,
            )
        );

        $this->indicatorProvider = $this->createMock(IndicatorProviderInterface::class);
        $this->entryZoneCalculator = $this->createMock(EntryZoneCalculator::class);
        $this->executionSelector = $this->createMock(ExecutionSelector::class);

        $this->handler = new TradingDecisionHandler(
            tradeEntryService: $this->tradeEntryService,
            requestBuilder: $requestBuilder,
            executionSelector: $this->executionSelector,
            indicatorProvider: $this->indicatorProvider,
            logger: $this->logger,
            positionsFlowLogger: $this->positionsFlowLogger,
            orderJourneyLogger: $this->createMock(LoggerInterface::class),
            tradeEntryConfig: $tradeEntryConfig,
            mtfConfig: new MtfValidationConfig(),
            mtfSwitchRepository: $this->createMock(\App\Repository\MtfSwitchRepository::class),
            auditLogger: $this->auditLogger,
            entryZoneCalculator: $this->entryZoneCalculator,
            lifecycleContextFactory: new LifecycleContextFactory(),
        );
    }

    public function testHandleTradingDecisionReturnsSameResultWhenNotReady(): void
    {
        $symbolResult = new SymbolResultDto('BTCUSDT', 'PROCESSING');
        $dto = new MtfRunDto(symbol: 'BTCUSDT', profile: 'scalper');

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertSame($symbolResult, $result);
    }

    public function testHandleTradingDecisionSkipsWhenConditionsNotMet(): void
    {
        $symbolResult = new SymbolResultDto(
            'BTCUSDT',
            'READY',
            '5m',
            SignalSide::LONG->value,
            null,
            null,
            null,
            50000.0,
            null
        );
        $dto = new MtfRunDto(symbol: 'BTCUSDT', profile: 'scalper');

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertSame('READY', $result->status);
        $this->assertNotNull($result->tradingDecision);
        $this->assertEquals('skipped', $result->tradingDecision['status']);
    }

    public function testHandleTradingDecisionWithSuccessfulExecution(): void
    {
        $symbolResult = new SymbolResultDto(
            'BTCUSDT',
            'READY',
            '1m',
            SignalSide::LONG->value,
            null,
            null,
            null,
            50250.0,
            35.0
        );
        $dto = new MtfRunDto(symbol: 'BTCUSDT', profile: 'scalper');

        // plus de résolution de prix côté MTF — le preflight/builder gère best bid/ask

        $execution = new ExecutionResult(
            clientOrderId: 'cid123',
            exchangeOrderId: 'ex123',
            status: 'submitted',
            raw: ['foo' => 'bar']
        );

        $this->tradeEntryService
            ->expects($this->once())
            ->method('buildAndExecute')
            ->with(
                $this->callback(function (TradeEntryRequest $request): bool {
                    $this->assertEquals('BTCUSDT', $request->symbol);
                    $this->assertEquals('limit', $request->orderType);
                    $this->assertEquals('isolated', $request->openType);
                    $this->assertEquals(4, $request->orderMode);
                    $this->assertEquals(100.0, $request->initialMarginUsdt);
                    $this->assertEqualsWithDelta(0.02, $request->riskPct, 1e-6);
                    $this->assertEquals(50250.0, $request->entryLimitHint);

                    return true;
                }),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->isInstanceOf(\App\Logging\Dto\LifecycleContextBuilder::class)
            )
            ->willReturn($execution);

        $this->auditLogger
            ->expects($this->once())
            ->method('logAction')
            ->with(
                'TRADE_ENTRY_EXECUTED',
                'TRADE_ENTRY',
                'BTCUSDT',
                $this->arrayHasKey('status')
            );

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertEquals('READY', $result->status);
        $this->assertEquals('submitted', $result->tradingDecision['status']);
        $this->assertEquals('cid123', $result->tradingDecision['client_order_id']);
        $this->assertEquals('ex123', $result->tradingDecision['exchange_order_id']);
    }

    public function testHandleTradingDecisionHandlesException(): void
    {
        $symbolResult = new SymbolResultDto(
            'BTCUSDT',
            'READY',
            '1m',
            SignalSide::SHORT->value,
            null,
            null,
            null,
            20000.0,
            20.0
        );
        $dto = new MtfRunDto(symbol: 'BTCUSDT', profile: 'scalper');

        // pas d'appel au resolver, attentes retirées

        $this->tradeEntryService
            ->expects($this->once())
            ->method('buildAndExecute')
            ->willThrowException(new \RuntimeException('exchange failure'));

        $this->auditLogger
            ->expects($this->once())
            ->method('logAction')
            ->with(
                'TRADE_ENTRY_FAILED',
                'TRADE_ENTRY',
                'BTCUSDT',
                $this->arrayHasKey('error')
            );

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertEquals('READY', $result->status);
        $this->assertEquals('error', $result->tradingDecision['status']);
        $this->assertEquals('exchange failure', $result->tradingDecision['error']);
    }

    public function testBuildSelectorContextCalculatesEntryZoneWidthPct(): void
    {
        $symbolResult = new SymbolResultDto(
            'BTCUSDT',
            'READY',
            '15m',
            SignalSide::LONG->value,
            null,
            null,
            null,
            50000.0,
            null
        );

        // Mock ATR 15m
        $this->indicatorProvider
            ->expects($this->once())
            ->method('getAtr')
            ->with('BTCUSDT', '15m')
            ->willReturn(50.0);

        // Mock EntryZoneCalculator pour retourner une zone valide
        $entryZone = new EntryZone(
            min: 49900.0,
            max: 50100.0,
            rationale: 'test zone'
        );

        $this->entryZoneCalculator
            ->expects($this->once())
            ->method('compute')
            ->with('BTCUSDT', Side::Long, null, $this->anything())
            ->willReturn($entryZone);

        // Utiliser la réflexion pour accéder à buildSelectorContext (méthode privée)
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('buildSelectorContext');
        $method->setAccessible(true);

        $context = $method->invoke($this->handler, $symbolResult);

        // Vérifier que entry_zone_width_pct est calculé
        // (max - min) / pivot * 100 = (50100 - 49900) / 50000 * 100 = 0.4%
        $this->assertArrayHasKey('entry_zone_width_pct', $context);
        $this->assertIsFloat($context['entry_zone_width_pct']);
        $expectedWidth = (50100.0 - 49900.0) / 50000.0 * 100.0;
        $this->assertEqualsWithDelta($expectedWidth, $context['entry_zone_width_pct'], 0.01);
    }

    public function testBuildSelectorContextThrowsErrorWhenEntryZoneCalculatorFails(): void
    {
        $symbolResult = new SymbolResultDto(
            'BTCUSDT',
            'READY',
            '15m',
            SignalSide::LONG->value,
            null,
            null,
            null,
            50000.0,
            null
        );

        // Mock ATR 15m
        $this->indicatorProvider
            ->expects($this->once())
            ->method('getAtr')
            ->with('BTCUSDT', '15m')
            ->willReturn(50.0);

        // Mock EntryZoneCalculator pour throw une exception
        $this->entryZoneCalculator
            ->expects($this->once())
            ->method('compute')
            ->with('BTCUSDT', Side::Long, null, $this->anything())
            ->willThrowException(new \RuntimeException('Entry zone calculation failed'));

        // Utiliser la réflexion pour accéder à buildSelectorContext
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('buildSelectorContext');
        $method->setAccessible(true);

        // Vérifier que l'exception est propagée
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Entry zone calculation failed');

        $method->invoke($this->handler, $symbolResult);
    }

    public function testBuildSelectorContextIncludesEntryZoneWidthPctInContext(): void
    {
        $symbolResult = new SymbolResultDto(
            'ETHUSDT',
            'READY',
            '15m',
            SignalSide::SHORT->value,
            null,
            null,
            null,
            3000.0,
            null
        );

        $this->indicatorProvider
            ->expects($this->once())
            ->method('getAtr')
            ->with('ETHUSDT', '15m')
            ->willReturn(30.0);

        $entryZone = new EntryZone(
            min: 2985.0,
            max: 3015.0,
            rationale: 'test zone'
        );

        $this->entryZoneCalculator
            ->expects($this->once())
            ->method('compute')
            ->with('ETHUSDT', Side::Short, null, $this->anything())
            ->willReturn($entryZone);

        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('buildSelectorContext');
        $method->setAccessible(true);

        $context = $method->invoke($this->handler, $symbolResult);

        // Vérifier que entry_zone_width_pct est présent et calculé correctement
        $this->assertArrayHasKey('entry_zone_width_pct', $context);
        $this->assertIsFloat($context['entry_zone_width_pct']);
        $expectedWidth = (3015.0 - 2985.0) / 3000.0 * 100.0; // 1.0%
        $this->assertEqualsWithDelta($expectedWidth, $context['entry_zone_width_pct'], 0.01);
    }
}
