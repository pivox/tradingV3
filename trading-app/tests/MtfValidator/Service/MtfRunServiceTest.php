<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\MtfValidator\Service\MtfRunService;
use App\MtfValidator\Service\Runner\MtfRunOrchestrator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class MtfRunServiceTest extends TestCase
{
    private MtfRunService $service;
    /** @var MtfRunOrchestrator&MockObject */
    private MtfRunOrchestrator $orchestrator;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->orchestrator = $this->createMock(MtfRunOrchestrator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new MtfRunService($this->orchestrator, $this->logger);
    }

    public function testRunSuccessBuildsResponseFromPipeline(): void
    {
        $request = new MtfRunRequestDto(
            symbols: ['BTCUSDT', 'ETHUSDT'],
            dryRun: false,
            forceRun: false,
            currentTf: null,
            forceTimeframeCheck: false,
            skipContextValidation: false,
            lockPerSymbol: false
        );

        $yields = [
            ['symbol' => 'BTCUSDT', 'result' => ['status' => 'SUCCESS'], 'progress' => ['percentage' => 50, 'status' => 'SUCCESS']],
            ['symbol' => 'ETHUSDT', 'result' => ['status' => 'ERROR', 'message' => 'boom'], 'progress' => ['percentage' => 100, 'status' => 'ERROR']],
        ];

        $finalReturn = [
            'summary' => [
                'run_id' => Uuid::uuid4()->toString(),
                'status' => 'completed',
                'success_rate' => 50.0,
                'symbols_processed' => 2,
                'symbols_successful' => 1,
                'symbols_failed' => 1,
                'symbols_skipped' => 0,
            ],
            'results' => [
                'BTCUSDT' => ['status' => 'SUCCESS'],
                'ETHUSDT' => ['status' => 'ERROR'],
            ],
        ];

        $this->orchestrator->expects($this->once())
            ->method('execute')
            ->with($this->callback(function (MtfRunDto $dto) use ($request) {
                return $dto->symbols === $request->symbols
                    && $dto->dryRun === $request->dryRun
                    && $dto->forceRun === $request->forceRun
                    && $dto->forceTimeframeCheck === $request->forceTimeframeCheck
                    && $dto->currentTf === $request->currentTf
                    && $dto->lockPerSymbol === $request->lockPerSymbol
                    && $dto->skipContextValidation === $request->skipContextValidation;
            }),
                $this->isInstanceOf(Uuid::class))
            ->willReturn($this->createGenerator($yields, $finalReturn));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('[MTF Run] Starting execution', $this->arrayHasKey('run_id'));

        $response = $this->service->run($request);

        $this->assertInstanceOf(MtfRunResponseDto::class, $response);
        $this->assertSame('partial_success', $response->status);
        $this->assertSame(2, $response->symbolsRequested);
        $this->assertSame(2, $response->symbolsProcessed);
        $this->assertSame(1, $response->symbolsSuccessful);
        $this->assertSame(1, $response->symbolsFailed);
        $this->assertSame(0, $response->symbolsSkipped);
        $this->assertSame(50.0, $response->successRate);
        $this->assertCount(2, $response->results);
        $this->assertCount(1, $response->errors);
    }

    public function testRunHandlesAllErrors(): void
    {
        $request = new MtfRunRequestDto(symbols: ['BTCUSDT']);

        $yields = [
            ['symbol' => 'BTCUSDT', 'result' => ['status' => 'ERROR', 'message' => 'fail'], 'progress' => ['percentage' => 100, 'status' => 'ERROR']],
        ];

        $finalReturn = [
            'summary' => [
                'run_id' => Uuid::uuid4()->toString(),
                'status' => 'completed',
                'success_rate' => 0.0,
                'symbols_processed' => 1,
                'symbols_successful' => 0,
                'symbols_failed' => 1,
                'symbols_skipped' => 0,
            ],
            'results' => ['BTCUSDT' => ['status' => 'ERROR', 'message' => 'fail']],
        ];

        $this->orchestrator->expects($this->once())
            ->method('execute')
            ->willReturn($this->createGenerator($yields, $finalReturn));

        $response = $this->service->run($request);

        $this->assertSame('error', $response->status);
        $this->assertSame(1, $response->symbolsFailed);
        $this->assertCount(1, $response->errors);
    }

    public function testRunWithDryRunKeepsStatus(): void
    {
        $request = new MtfRunRequestDto(symbols: ['BTCUSDT'], dryRun: true);

        $yields = [
            ['symbol' => 'BTCUSDT', 'result' => ['status' => 'SUCCESS'], 'progress' => ['percentage' => 100, 'status' => 'SUCCESS']],
        ];

        $finalReturn = [
            'summary' => [
                'run_id' => Uuid::uuid4()->toString(),
                'status' => 'completed',
                'success_rate' => 100.0,
                'symbols_processed' => 1,
                'symbols_successful' => 1,
                'symbols_failed' => 0,
                'symbols_skipped' => 0,
            ],
            'results' => ['BTCUSDT' => ['status' => 'SUCCESS']],
        ];

        $this->orchestrator->expects($this->once())
            ->method('execute')
            ->willReturn($this->createGenerator($yields, $finalReturn));

        $response = $this->service->run($request);

        $this->assertTrue($response->isSuccess());
        $this->assertSame(1, $response->symbolsSuccessful);
        $this->assertSame(0, $response->symbolsFailed);
    }

    public function testRunLogsErrorsWhenPipelineFails(): void
    {
        $request = new MtfRunRequestDto(symbols: ['BTCUSDT']);

        $this->orchestrator->expects($this->once())
            ->method('execute')
            ->willThrowException(new \RuntimeException('orchestrator down'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('[MTF Run] Execution failed', $this->arrayHasKey('error'));

        $this->expectException(\RuntimeException::class);
        $this->service->run($request);
    }

    public function testHealthCheckAlwaysReturnsTrue(): void
    {
        $this->assertTrue($this->service->healthCheck());
    }

    private function createGenerator(array $yields, array $return): \Generator
    {
        foreach ($yields as $yield) {
            yield $yield;
        }

        return $return;
    }
}
