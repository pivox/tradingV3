<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Common\Enum\SignalSide;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Service\TradingDecisionHandler;
use App\Service\Price\TradingPriceResolution;
use App\Service\Price\TradingPriceResolver;
use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Service\TradeEntryService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TradingDecisionHandlerTest extends TestCase
{
    private TradingDecisionHandler $handler;
    /** @var TradeEntryService&MockObject */
    private TradeEntryService $tradeEntryService;
    private TradingPriceResolver $tradingPriceResolver;
    private AuditLoggerInterface $auditLogger;
    private LoggerInterface $logger;
    private LoggerInterface $positionsFlowLogger;

    protected function setUp(): void
    {
        $this->tradeEntryService = $this->createMock(TradeEntryService::class);
        $this->tradingPriceResolver = $this->createMock(TradingPriceResolver::class);
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

        $this->handler = new TradingDecisionHandler(
            tradeEntryService: $this->tradeEntryService,
            tradingPriceResolver: $this->tradingPriceResolver,
            auditLogger: $this->auditLogger,
            logger: $this->logger,
            positionsFlowLogger: $this->positionsFlowLogger,
            tradeEntryDefaults: $defaults,
        );
    }

    public function testHandleTradingDecisionReturnsSameResultWhenNotReady(): void
    {
        $symbolResult = new SymbolResultDto('BTCUSDT', 'PROCESSING');
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

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
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

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
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $priceObj = new TradingPriceResolution(
            price: 50250.0,
            source: 'test',
            snapshotPrice: 50250.0,
            providerPrice: 50255.0,
            fallbackPrice: null,
            bestBid: null,
            bestAsk: null,
            relativeDiff: null,
            allowedDiff: null,
            fallbackEngaged: false
        );

        $this->tradingPriceResolver
            ->expects($this->once())
            ->method('resolve')
            ->with(
                'BTCUSDT',
                SignalSide::LONG,
                50250.0,
                35.0
            )
            ->willReturn($priceObj);

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
                $this->assertEquals('BTCUSDT', $request->symbol);
                $this->assertEquals('limit', $request->orderType);
                $this->assertEquals('isolated', $request->openType);
                $this->assertEquals(4, $request->orderMode);
                $this->assertEquals(100.0, $request->initialMarginUsdt);
                $this->assertEqualsWithDelta(0.02, $request->riskPct, 1e-6);
                $this->assertEquals(50250.0, $request->entryLimitHint);

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
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $priceObj = new TradingPriceResolution(
            price: 20000.0,
            source: 'test',
            snapshotPrice: 20000.0,
            providerPrice: 20001.0,
            fallbackPrice: null,
            bestBid: null,
            bestAsk: null,
            relativeDiff: null,
            allowedDiff: null,
            fallbackEngaged: false
        );

        $this->tradingPriceResolver
            ->expects($this->once())
            ->method('resolve')
            ->willReturn($priceObj);

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
}
