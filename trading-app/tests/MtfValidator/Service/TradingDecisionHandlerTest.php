<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Common\Enum\SignalSide;
use App\Contract\EntryTrade\TradeContextInterface;
use App\Contract\EntryTrade\TradingDecisionInterface;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Service\TradingDecisionHandler;
use App\Service\Price\TradingPriceResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TradingDecisionHandlerTest extends TestCase
{
    private TradingDecisionHandler $handler;
    private TradingDecisionInterface $tradingDecisionService;
    private TradeContextInterface $tradeContext;
    private TradingPriceResolver $tradingPriceResolver;
    private AuditLoggerInterface $auditLogger;
    private LoggerInterface $logger;
    private LoggerInterface $positionsFlowLogger;

    protected function setUp(): void
    {
        $this->tradingDecisionService = $this->createMock(TradingDecisionInterface::class);
        $this->tradeContext = $this->createMock(TradeContextInterface::class);
        $this->tradingPriceResolver = $this->createMock(TradingPriceResolver::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->positionsFlowLogger = $this->createMock(LoggerInterface::class);

        $this->handler = new TradingDecisionHandler(
            $this->tradingDecisionService,
            $this->tradeContext,
            $this->tradingPriceResolver,
            $this->auditLogger,
            $this->logger,
            $this->positionsFlowLogger
        );
    }

    public function testHandleTradingDecisionWithError(): void
    {
        $symbolResult = new SymbolResultDto('BTCUSDT', 'ERROR');
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertSame($symbolResult, $result);
    }

    public function testHandleTradingDecisionWithSkipped(): void
    {
        $symbolResult = new SymbolResultDto('BTCUSDT', 'SKIPPED');
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertSame($symbolResult, $result);
    }

    public function testHandleTradingDecisionWithNonReadyStatus(): void
    {
        $symbolResult = new SymbolResultDto('BTCUSDT', 'PROCESSING');
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertSame($symbolResult, $result);
    }

    public function testHandleTradingDecisionWithMissingTradingContext(): void
    {
        $symbolResult = new SymbolResultDto('BTCUSDT', 'READY');
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $this->tradeContext->expects($this->once())
            ->method('getAccountBalance')
            ->willThrowException(new \RuntimeException('Context error'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('[Trading Decision] Unable to resolve trading context', $this->isType('array'));

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
        $this->assertEquals('READY', $result->status);
        $this->assertIsArray($result->tradingDecision);
        $this->assertEquals('skipped', $result->tradingDecision['status']);
        $this->assertEquals('missing_trading_context', $result->tradingDecision['reason']);
    }

    public function testHandleTradingDecisionWithZeroBalance(): void
    {
        $symbolResult = new SymbolResultDto('BTCUSDT', 'READY');
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $this->tradeContext->expects($this->once())
            ->method('getAccountBalance')
            ->willReturn(0.0);

        $this->tradeContext->expects($this->once())
            ->method('getRiskPercentage')
            ->willReturn(0.0);

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
        $this->assertEquals('READY', $result->status);
        $this->assertIsArray($result->tradingDecision);
        $this->assertEquals('skipped', $result->tradingDecision['status']);
        $this->assertEquals('missing_trading_context', $result->tradingDecision['reason']);
    }

    public function testHandleTradingDecisionWithWrongTimeframe(): void
    {
        $symbolResult = new SymbolResultDto(
            'BTCUSDT',
            'READY',
            '5m', // Wrong timeframe
            'BUY',
            null,
            null,
            null,
            50000.0,
            100.0
        );
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $this->tradeContext->expects($this->once())
            ->method('getAccountBalance')
            ->willReturn(1000.0);

        $this->tradeContext->expects($this->once())
            ->method('getRiskPercentage')
            ->willReturn(0.02);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('[Trading Decision] Skipping trading decision (execution_tf not 1m)', $this->isType('array'));

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
        $this->assertIsArray($result->tradingDecision);
        $this->assertEquals('skipped', $result->tradingDecision['status']);
        $this->assertEquals('execution_tf_not_1m', $result->tradingDecision['reason']);
    }

    public function testHandleTradingDecisionWithMissingPriceOrAtr(): void
    {
        $symbolResult = new SymbolResultDto(
            'BTCUSDT',
            'READY',
            '1m',
            'BUY',
            null,
            null,
            null,
            null, // Missing price
            100.0
        );
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $this->tradeContext->expects($this->once())
            ->method('getAccountBalance')
            ->willReturn(1000.0);

        $this->tradeContext->expects($this->once())
            ->method('getRiskPercentage')
            ->willReturn(0.02);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('[Trading Decision] Missing price or ATR', $this->isType('array'));

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
        $this->assertIsArray($result->tradingDecision);
        $this->assertEquals('skipped', $result->tradingDecision['status']);
        $this->assertEquals('trading_conditions_not_met', $result->tradingDecision['reason']);
    }

    public function testHandleTradingDecisionWithPriceResolutionFailure(): void
    {
        $symbolResult = new SymbolResultDto(
            'BTCUSDT',
            'READY',
            '1m',
            'BUY',
            null,
            null,
            null,
            50000.0,
            100.0
        );
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $this->tradeContext->expects($this->once())
            ->method('getAccountBalance')
            ->willReturn(1000.0);

        $this->tradeContext->expects($this->once())
            ->method('getRiskPercentage')
            ->willReturn(0.02);

        $this->tradingPriceResolver->expects($this->once())
            ->method('resolve')
            ->willReturn(null);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('[Trading Decision] Unable to resolve trading price', $this->isType('array'));

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
        $this->assertIsArray($result->tradingDecision);
        $this->assertEquals('skipped', $result->tradingDecision['status']);
        $this->assertEquals('price_resolution_failed', $result->tradingDecision['reason']);
    }

    public function testHandleTradingDecisionSuccess(): void
    {
        $symbolResult = new SymbolResultDto(
            'BTCUSDT',
            'READY',
            '1m',
            'BUY',
            null,
            null,
            ['context_fully_aligned' => true, 'context_dir' => 'BUY'],
            50000.0,
            100.0
        );
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $this->tradeContext->expects($this->once())
            ->method('getAccountBalance')
            ->willReturn(1000.0);

        $this->tradeContext->expects($this->once())
            ->method('getRiskPercentage')
            ->willReturn(0.02);

        $priceResolution = (object) [
            'price' => 50000.0,
            'source' => 'bitmart_last_price'
        ];

        $this->tradingPriceResolver->expects($this->once())
            ->method('resolve')
            ->willReturn($priceResolution);

        $this->tradeContext->expects($this->once())
            ->method('getTimeframeMultiplier')
            ->with('1m')
            ->willReturn(1.0);

        $tradingDecision = [
            'status' => 'success',
            'execution_result' => [
                'main_order' => ['order_id' => 'order123']
            ]
        ];

        $this->tradingDecisionService->expects($this->once())
            ->method('makeTradingDecision')
            ->with(
                'BTCUSDT',
                SignalSide::BUY,
                50000.0,
                100.0,
                1000.0,
                0.02,
                true, // High conviction
                1.0
            )
            ->willReturn($tradingDecision);

        $this->positionsFlowLogger->expects($this->exactly(2))
            ->method('info');

        $this->auditLogger->expects($this->once())
            ->method('logTradingAction')
            ->with('TRADING_DECISION', 'BTCUSDT', 0.0, 0.0, 'order123');

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
        $this->assertEquals('READY', $result->status);
        $this->assertEquals($tradingDecision, $result->tradingDecision);
    }

    public function testHandleTradingDecisionWithTradingServiceError(): void
    {
        $symbolResult = new SymbolResultDto(
            'BTCUSDT',
            'READY',
            '1m',
            'BUY',
            null,
            null,
            null,
            50000.0,
            100.0
        );
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $this->tradeContext->expects($this->once())
            ->method('getAccountBalance')
            ->willReturn(1000.0);

        $this->tradeContext->expects($this->once())
            ->method('getRiskPercentage')
            ->willReturn(0.02);

        $priceResolution = (object) ['price' => 50000.0];

        $this->tradingPriceResolver->expects($this->once())
            ->method('resolve')
            ->willReturn($priceResolution);

        $this->tradeContext->expects($this->once())
            ->method('getTimeframeMultiplier')
            ->willReturn(1.0);

        $this->tradingDecisionService->expects($this->once())
            ->method('makeTradingDecision')
            ->willThrowException(new \RuntimeException('Trading service error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('[Trading Decision] Failed to execute trading decision', $this->isType('array'));

        $this->positionsFlowLogger->expects($this->once())
            ->method('error');

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
        $this->assertEquals('READY', $result->status);
        $this->assertIsArray($result->tradingDecision);
        $this->assertEquals('error', $result->tradingDecision['status']);
        $this->assertEquals('Trading service error', $result->tradingDecision['error']);
    }

    public function testHandleTradingDecisionWithDryRun(): void
    {
        $symbolResult = new SymbolResultDto('BTCUSDT', 'READY');
        $dto = new MtfRunDto(symbols: ['BTCUSDT'], dryRun: true);

        $result = $this->handler->handleTradingDecision($symbolResult, $dto);

        $this->assertSame($symbolResult, $result);
    }
}
