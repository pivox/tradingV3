<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Service\MtfService;
use App\MtfValidator\Service\SymbolProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class SymbolProcessorTest extends TestCase
{
    private SymbolProcessor $symbolProcessor;
    private MtfService $mtfService;
    private LoggerInterface $logger;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        $this->mtfService = $this->createMock(MtfService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        
        $this->symbolProcessor = new SymbolProcessor(
            $this->mtfService,
            $this->logger,
            $this->clock
        );
    }

    public function testProcessSymbolSuccess(): void
    {
        $symbol = 'BTCUSDT';
        $runId = Uuid::uuid4();
        $dto = new MtfRunDto(symbols: [$symbol]);
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $mtfResult = [
            'status' => 'SUCCESS',
            'execution_tf' => '1m',
            'signal_side' => 'BUY',
            'current_price' => 50000.0,
            'atr' => 100.0
        ];

        $generator = $this->createMock(\Generator::class);
        $generator->expects($this->once())
            ->method('getReturn')
            ->willReturn($mtfResult);

        $this->mtfService->expects($this->once())
            ->method('runForSymbol')
            ->with($runId, $symbol, $now, null, false, false)
            ->willReturn($generator);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('[Symbol Processor] Processing symbol', $this->isType('array'));

        $result = $this->symbolProcessor->processSymbol($symbol, $runId, $dto, $now);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
        $this->assertEquals($symbol, $result->symbol);
        $this->assertEquals('SUCCESS', $result->status);
        $this->assertEquals('1m', $result->executionTf);
        $this->assertEquals('BUY', $result->signalSide);
        $this->assertEquals(50000.0, $result->currentPrice);
        $this->assertEquals(100.0, $result->atr);
    }

    public function testProcessSymbolWithError(): void
    {
        $symbol = 'BTCUSDT';
        $runId = Uuid::uuid4();
        $dto = new MtfRunDto(symbols: [$symbol]);
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $this->mtfService->expects($this->once())
            ->method('runForSymbol')
            ->willThrowException(new \RuntimeException('MTF service error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('[Symbol Processor] Error processing symbol', $this->callback(function ($data) {
                return $data['symbol'] === $symbol &&
                       $data['error'] === 'MTF service error';
            }));

        $result = $this->symbolProcessor->processSymbol($symbol, $runId, $dto, $now);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
        $this->assertEquals($symbol, $result->symbol);
        $this->assertEquals('ERROR', $result->status);
        $this->assertIsArray($result->error);
        $this->assertEquals('MTF service error', $result->error['message']);
    }

    public function testProcessSymbolWithNullResult(): void
    {
        $symbol = 'BTCUSDT';
        $runId = Uuid::uuid4();
        $dto = new MtfRunDto(symbols: [$symbol]);
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $generator = $this->createMock(\Generator::class);
        $generator->expects($this->once())
            ->method('getReturn')
            ->willReturn(null);

        $this->mtfService->expects($this->once())
            ->method('runForSymbol')
            ->willReturn($generator);

        $result = $this->symbolProcessor->processSymbol($symbol, $runId, $dto, $now);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
        $this->assertEquals($symbol, $result->symbol);
        $this->assertEquals('ERROR', $result->status);
        $this->assertIsArray($result->error);
        $this->assertEquals('No result from MTF service', $result->error['message']);
    }

    public function testProcessSymbolWithForceRun(): void
    {
        $symbol = 'BTCUSDT';
        $runId = Uuid::uuid4();
        $dto = new MtfRunDto(symbols: [$symbol], forceRun: true);
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $generator = $this->createMock(\Generator::class);
        $generator->expects($this->once())
            ->method('getReturn')
            ->willReturn(['status' => 'SUCCESS']);

        $this->mtfService->expects($this->once())
            ->method('runForSymbol')
            ->with($runId, $symbol, $now, null, false, true)
            ->willReturn($generator);

        $result = $this->symbolProcessor->processSymbol($symbol, $runId, $dto, $now);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
        $this->assertEquals('SUCCESS', $result->status);
    }

    public function testProcessSymbolWithForceTimeframeCheck(): void
    {
        $symbol = 'BTCUSDT';
        $runId = Uuid::uuid4();
        $dto = new MtfRunDto(symbols: [$symbol], forceTimeframeCheck: true);
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $generator = $this->createMock(\Generator::class);
        $generator->expects($this->once())
            ->method('getReturn')
            ->willReturn(['status' => 'SUCCESS']);

        $this->mtfService->expects($this->once())
            ->method('runForSymbol')
            ->with($runId, $symbol, $now, null, true, false)
            ->willReturn($generator);

        $result = $this->symbolProcessor->processSymbol($symbol, $runId, $dto, $now);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
        $this->assertEquals('SUCCESS', $result->status);
    }

    public function testProcessSymbolWithCurrentTf(): void
    {
        $symbol = 'BTCUSDT';
        $runId = Uuid::uuid4();
        $dto = new MtfRunDto(symbols: [$symbol], currentTf: '1h');
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $generator = $this->createMock(\Generator::class);
        $generator->expects($this->once())
            ->method('getReturn')
            ->willReturn(['status' => 'SUCCESS']);

        $this->mtfService->expects($this->once())
            ->method('runForSymbol')
            ->with($runId, $symbol, $now, '1h', false, false)
            ->willReturn($generator);

        $result = $this->symbolProcessor->processSymbol($symbol, $runId, $dto, $now);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
        $this->assertEquals('SUCCESS', $result->status);
    }

    public function testProcessSymbolLogsCorrectly(): void
    {
        $symbol = 'BTCUSDT';
        $runId = Uuid::uuid4();
        $dto = new MtfRunDto(symbols: [$symbol], forceRun: true, forceTimeframeCheck: true);
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $generator = $this->createMock(\Generator::class);
        $generator->expects($this->once())
            ->method('getReturn')
            ->willReturn(['status' => 'SUCCESS']);

        $this->mtfService->expects($this->once())
            ->method('runForSymbol')
            ->willReturn($generator);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('[Symbol Processor] Processing symbol', $this->callback(function ($data) {
                return $data['symbol'] === $symbol &&
                       $data['run_id'] === $runId->toString() &&
                       $data['force_run'] === true &&
                       $data['force_timeframe_check'] === true;
            }));

        $result = $this->symbolProcessor->processSymbol($symbol, $runId, $dto, $now);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
    }

    public function testProcessSymbolWithComplexResult(): void
    {
        $symbol = 'BTCUSDT';
        $runId = Uuid::uuid4();
        $dto = new MtfRunDto(symbols: [$symbol]);
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $mtfResult = [
            'status' => 'READY',
            'execution_tf' => '1m',
            'signal_side' => 'SELL',
            'current_price' => 45000.0,
            'atr' => 150.0,
            'context' => ['trend' => 'bearish'],
            'trading_decision' => ['status' => 'pending']
        ];

        $generator = $this->createMock(\Generator::class);
        $generator->expects($this->once())
            ->method('getReturn')
            ->willReturn($mtfResult);

        $this->mtfService->expects($this->once())
            ->method('runForSymbol')
            ->willReturn($generator);

        $result = $this->symbolProcessor->processSymbol($symbol, $runId, $dto, $now);

        $this->assertInstanceOf(SymbolResultDto::class, $result);
        $this->assertEquals('READY', $result->status);
        $this->assertEquals('1m', $result->executionTf);
        $this->assertEquals('SELL', $result->signalSide);
        $this->assertEquals(45000.0, $result->currentPrice);
        $this->assertEquals(150.0, $result->atr);
        $this->assertEquals(['trend' => 'bearish'], $result->context);
        $this->assertEquals(['status' => 'pending'], $result->tradingDecision);
    }
}
