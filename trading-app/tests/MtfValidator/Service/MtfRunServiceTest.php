<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\MtfValidator\Service\MtfRunService;
use App\MtfValidator\Service\Runner\MtfRunOrchestrator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class MtfRunServiceTest extends TestCase
{
    private MtfRunService $mtfRunService;
    private MtfRunOrchestrator $orchestrator;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->orchestrator = $this->createMock(MtfRunOrchestrator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->mtfRunService = new MtfRunService($this->orchestrator, $this->logger);
    }

    public function testRunSuccess(): void
    {
        $dto = new MtfRunDto(
            symbols: ['BTCUSDT', 'ETHUSDT'],
            dryRun: false,
            forceRun: false
        );

        $expectedResult = [
            'summary' => ['run_id' => 'test', 'status' => 'completed'],
            'results' => ['BTCUSDT' => ['status' => 'SUCCESS']]
        ];

        $this->orchestrator->expects($this->once())
            ->method('execute')
            ->with($dto, $this->isInstanceOf(Uuid::class))
            ->willReturn(new \ArrayIterator($expectedResult));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('[MTF Run] Starting execution', $this->isType('array'));

        $result = iterator_to_array($this->mtfRunService->run($dto));
        $this->assertEquals($expectedResult, $result);
    }

    public function testRunWithDryRun(): void
    {
        $dto = new MtfRunDto(
            symbols: ['BTCUSDT'],
            dryRun: true,
            forceRun: false
        );

        $this->orchestrator->expects($this->once())
            ->method('execute')
            ->willReturn(new \ArrayIterator([]));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('[MTF Run] Starting execution', $this->callback(function ($data) {
                return $data['dry_run'] === true;
            }));

        $result = iterator_to_array($this->mtfRunService->run($dto));
        $this->assertIsArray($result);
    }

    public function testRunWithForceRun(): void
    {
        $dto = new MtfRunDto(
            symbols: ['BTCUSDT'],
            dryRun: false,
            forceRun: true
        );

        $this->orchestrator->expects($this->once())
            ->method('execute')
            ->willReturn(new \ArrayIterator([]));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('[MTF Run] Starting execution', $this->callback(function ($data) {
                return $data['force_run'] === true;
            }));

        $result = iterator_to_array($this->mtfRunService->run($dto));
        $this->assertIsArray($result);
    }

    public function testRunWithSpecificTimeframe(): void
    {
        $dto = new MtfRunDto(
            symbols: ['BTCUSDT'],
            dryRun: false,
            forceRun: false,
            currentTf: '1h'
        );

        $this->orchestrator->expects($this->once())
            ->method('execute')
            ->willReturn(new \ArrayIterator([]));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('[MTF Run] Starting execution', $this->callback(function ($data) {
                return $data['symbols_count'] === 1;
            }));

        $result = iterator_to_array($this->mtfRunService->run($dto));
        $this->assertIsArray($result);
    }

    public function testRunWithEmptySymbols(): void
    {
        $dto = new MtfRunDto(
            symbols: [],
            dryRun: false,
            forceRun: false
        );

        $this->orchestrator->expects($this->once())
            ->method('execute')
            ->willReturn(new \ArrayIterator([]));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('[MTF Run] Starting execution', $this->callback(function ($data) {
                return $data['symbols_count'] === 0;
            }));

        $result = iterator_to_array($this->mtfRunService->run($dto));
        $this->assertIsArray($result);
    }

    public function testRunWithException(): void
    {
        $dto = new MtfRunDto(
            symbols: ['BTCUSDT'],
            dryRun: false,
            forceRun: false
        );

        $exception = new \RuntimeException('Test error');

        $this->orchestrator->expects($this->once())
            ->method('execute')
            ->willThrowException($exception);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('[MTF Run] Execution failed', $this->callback(function ($data) {
                return $data['error'] === 'Test error';
            }));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test error');

        iterator_to_array($this->mtfRunService->run($dto));
    }

    public function testRunGeneratesUniqueRunId(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $this->orchestrator->expects($this->exactly(2))
            ->method('execute')
            ->with($dto, $this->isInstanceOf(Uuid::class))
            ->willReturn(new \ArrayIterator([]));

        // First run
        iterator_to_array($this->mtfRunService->run($dto));
        
        // Second run should generate different UUID
        iterator_to_array($this->mtfRunService->run($dto));
    }

    public function testRunLogsCorrectParameters(): void
    {
        $dto = new MtfRunDto(
            symbols: ['BTCUSDT', 'ETHUSDT'],
            dryRun: true,
            forceRun: false,
            currentTf: '1h',
            forceTimeframeCheck: true,
            lockPerSymbol: true
        );

        $this->orchestrator->expects($this->once())
            ->method('execute')
            ->willReturn(new \ArrayIterator([]));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('[MTF Run] Starting execution', $this->callback(function ($data) {
                return $data['symbols_count'] === 2 &&
                       $data['dry_run'] === true &&
                       $data['force_run'] === false;
            }));

        iterator_to_array($this->mtfRunService->run($dto));
    }
}
