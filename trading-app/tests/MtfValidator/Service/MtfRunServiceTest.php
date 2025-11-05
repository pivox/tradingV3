<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use App\MtfValidator\Service\MtfRunService;
use App\MtfValidator\Service\Runner\MtfRunOrchestrator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;

final class MtfRunServiceTest extends TestCase
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

    public function testRunAggregatesGeneratorResults(): void
    {
        $request = new MtfRunRequestDto(symbols: ['BTCUSDT', 'ETHUSDT']);

        $generator = $this->createGenerator(
            [
                [
                    'symbol' => 'BTCUSDT',
                    'result' => ['status' => 'SUCCESS', 'symbol' => 'BTCUSDT'],
                ],
                [
                    'symbol' => 'ETHUSDT',
                    'result' => ['status' => 'ERROR', 'symbol' => 'ETHUSDT'],
                ],
                [
                    'symbol' => 'FINAL',
                    'result' => ['status' => 'completed', 'timestamp' => '2024-01-01 10:00:00'],
                ],
            ],
            [
                'summary' => [
                    'status' => 'completed',
                    'timestamp' => '2024-01-01 10:00:00',
                    'message' => 'All done',
                ],
                'results' => [
                    'BTCUSDT' => ['status' => 'SUCCESS'],
                    'ETHUSDT' => ['status' => 'ERROR'],
                ],
            ]
        );

        $this->orchestrator
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->callback(fn ($dto) => $dto instanceof MtfRunDto && $dto->symbols === ['BTCUSDT', 'ETHUSDT']),
                $this->isInstanceOf(UuidInterface::class)
            )
            ->willReturn($generator);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                '[MTF Run] Starting execution',
                $this->callback(fn (array $context): bool => $context['symbols_count'] === 2)
            );

        $response = $this->service->run($request);

        $this->assertInstanceOf(MtfRunResponseDto::class, $response);
        $this->assertSame('partial_success', $response->status);
        $this->assertSame(2, $response->symbolsProcessed);
        $this->assertSame(1, $response->symbolsSuccessful);
        $this->assertSame(1, $response->symbolsFailed);
        $this->assertSame(50.0, $response->successRate);
        $this->assertArrayHasKey('BTCUSDT', $response->results);
        $this->assertArrayHasKey('ETHUSDT', $response->results);
        $this->assertCount(1, $response->errors);
        $this->assertSame('ETHUSDT', $response->errors[0]['symbol']);
        $this->assertSame('All done', $response->message);
    }

    public function testRunFallsBackToStreamedResultsWhenFinalIsMissing(): void
    {
        $request = new MtfRunRequestDto(symbols: ['BTCUSDT']);

        $generator = $this->createGenerator([
            [
                'symbol' => 'BTCUSDT',
                'result' => [
                    'status' => 'GRACE_WINDOW',
                    'symbol' => 'BTCUSDT',
                ],
            ],
        ]);

        $this->orchestrator
            ->expects($this->once())
            ->method('execute')
            ->with($this->isInstanceOf(MtfRunDto::class), $this->isInstanceOf(UuidInterface::class))
            ->willReturn($generator);

        $this->logger->expects($this->once())->method('info');

        $response = $this->service->run($request);

        $this->assertSame('success', $response->status);
        $this->assertSame(1, $response->symbolsProcessed);
        $this->assertSame(0, $response->symbolsSuccessful);
        $this->assertSame(0, $response->symbolsFailed);
        $this->assertSame(1, $response->symbolsSkipped);
        $this->assertArrayHasKey('BTCUSDT', $response->results);
    }

    public function testRunRethrowsAndLogsOnFailure(): void
    {
        $request = new MtfRunRequestDto(symbols: ['BTCUSDT']);

        $this->orchestrator
            ->expects($this->once())
            ->method('execute')
            ->willThrowException(new \RuntimeException('boom'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                '[MTF Run] Execution failed',
                $this->callback(fn (array $context): bool => $context['error'] === 'boom')
            );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->service->run($request);
    }

    /**
     * @param array<int, array<string, mixed>> $yields
     */
    private function createGenerator(array $yields, ?array $returnValue = null): \Generator
    {
        foreach ($yields as $payload) {
            yield $payload;
        }

        return $returnValue;
    }
}
