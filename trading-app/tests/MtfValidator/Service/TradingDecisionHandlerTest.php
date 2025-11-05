<?php

declare(strict_types=1);

use App\Common\Enum\SignalSide;
use App\Config\{MtfValidationConfig, TradingDecisionConfig};
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\MtfValidator\Service\Decision\TradingDecisionService;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Service\Metrics\RunMetricsAggregator;
use App\MtfValidator\Service\TradingDecisionHandler;
use App\Repository\MtfSwitchRepository;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Service\TradeEntryService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class TradingDecisionHandlerTest extends TestCase
{
    private TradingDecisionHandler $handler;
    /** @var TradeEntryService&MockObject */
    private TradeEntryService $tradeEntryService;
    /** @var MtfSwitchRepository&MockObject */
    private MtfSwitchRepository $mtfSwitchRepository;
    /** @var AuditLoggerInterface&MockObject */
    private AuditLoggerInterface $auditLogger;
    private RunMetricsAggregator $metricsAggregator;
    private LoggerInterface $logger;
    private LoggerInterface $positionsFlowLogger;

    protected function setUp(): void
    {
        $this->tradeEntryService = $this->createMock(TradeEntryService::class);
        $this->mtfSwitchRepository = $this->createMock(MtfSwitchRepository::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $orderJourneyLogger = new NullLogger();
        $this->metricsAggregator = new RunMetricsAggregator($this->auditLogger, $orderJourneyLogger);

        $this->logger = new NullLogger();
        $this->positionsFlowLogger = new NullLogger();

        $decisionService = new TradingDecisionService(
            decisionConfig: new TradingDecisionConfig(),
            mtfConfig: new MtfValidationConfig(),
            mtfSwitchRepository: $this->mtfSwitchRepository,
            logger: $this->logger,
        );

        $this->handler = new TradingDecisionHandler(
            tradeEntryService: $this->tradeEntryService,
            decisionService: $decisionService,
            metricsAggregator: $this->metricsAggregator,
            logger: $this->logger,
            positionsFlowLogger: $this->positionsFlowLogger,
        );
    }

    public function testHandleTradingDecisionReturnsSameResultWhenNotReady(): void
    {
        $symbolResult = new SymbolResultDto('BTCUSDT', 'PROCESSING');
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertSame($symbolResult, $result);
    }

    public function testHandleTradingDecisionSkipsWhenExecutionTimeframeMissing(): void
    {
        $symbolResult = new SymbolResultDto(
            symbol: 'BTCUSDT',
            status: 'READY',
            executionTf: null,
            signalSide: SignalSide::LONG->value,
            tradingDecision: null,
            error: null,
            context: null,
            currentPrice: 50000.0,
            atr: null
        );
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertSame('READY', $result->status);
        $this->assertNotNull($result->tradingDecision);
        $this->assertSame('skipped', $result->tradingDecision['status']);
        $this->assertSame('trading_conditions_not_met', $result->tradingDecision['reason']);
    }

    public function testHandleTradingDecisionWithSuccessfulExecution(): void
    {
        $symbolResult = new SymbolResultDto(
            symbol: 'BTCUSDT',
            status: 'READY',
            executionTf: '1m',
            signalSide: SignalSide::LONG->value,
            tradingDecision: null,
            error: null,
            context: null,
            currentPrice: 50250.0,
            atr: 35.0
        );
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $execution = new ExecutionResult(
            clientOrderId: 'cid123',
            exchangeOrderId: 'ex123',
            status: 'submitted',
            raw: ['foo' => 'bar']
        );

        $this->tradeEntryService
            ->expects($this->once())
            ->method('buildAndExecute')
            ->with($this->callback(function (TradeEntryRequest $request): bool {
                $this->assertSame('BTCUSDT', $request->symbol);
                $this->assertSame('limit', $request->orderType);
                $this->assertSame('isolated', $request->openType);
                $this->assertSame(1, $request->orderMode);
                $this->assertSame(50.0, $request->initialMarginUsdt);
                $this->assertEqualsWithDelta(0.05, $request->riskPct, 1e-6);

                return true;
            }))
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

        $this->mtfSwitchRepository
            ->expects($this->once())
            ->method('turnOffSymbolFor15Minutes')
            ->with('BTCUSDT');

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertSame('submitted', $result->tradingDecision['status']);
        $this->assertSame('cid123', $result->tradingDecision['client_order_id']);
        $this->assertSame('ex123', $result->tradingDecision['exchange_order_id']);
    }

    public function testHandleTradingDecisionHandlesException(): void
    {
        $symbolResult = new SymbolResultDto(
            symbol: 'BTCUSDT',
            status: 'READY',
            executionTf: '1m',
            signalSide: SignalSide::SHORT->value,
            tradingDecision: null,
            error: null,
            context: null,
            currentPrice: 20000.0,
            atr: 20.0
        );
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

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

        $this->assertSame('error', $result->tradingDecision['status']);
        $this->assertSame('exchange failure', $result->tradingDecision['error']);
    }
}

