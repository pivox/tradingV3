<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\MtfValidator\Service\MtfRunService;
use App\MtfValidator\Service\Runner\MtfRunOrchestrator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;

final class MtfRunIntegrationTest extends TestCase
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

    public function testRunReturnsErrorStatusWhenAllSymbolsFail(): void
    {
        $request = new MtfRunRequestDto(symbols: ['BTCUSDT', 'ETHUSDT']);

        $generator = $this->createGenerator(
            [
                [
                    'symbol' => 'BTCUSDT',
                    'result' => ['status' => 'ERROR', 'symbol' => 'BTCUSDT', 'error' => ['code' => 'X1']],
                ],
                [
                    'symbol' => 'ETHUSDT',
                    'result' => ['status' => 'ERROR', 'symbol' => 'ETHUSDT', 'error' => ['code' => 'X2']],
                ],
            ],
            [
                'summary' => [
                    'status' => 'completed',
                    'timestamp' => '2024-02-02 09:30:00',
                    'message' => 'Run finished with errors',
                ],
                'results' => [
                    'BTCUSDT' => ['status' => 'ERROR'],
                    'ETHUSDT' => ['status' => 'ERROR'],
                ],
            ]
        );

        $this->orchestrator
            ->expects($this->once())
            ->method('execute')
            ->with($this->isInstanceOf(MtfRunDto::class), $this->isInstanceOf(UuidInterface::class))
            ->willReturn($generator);

        $this->logger->expects($this->once())->method('info');

        $response = $this->service->run($request);

        $this->assertSame('error', $response->status);
        $this->assertSame(0.0, $response->successRate);
        $this->assertCount(2, $response->errors);
        $this->assertSame('Run finished with errors', $response->message);
    }

    public function testRunUsesStreamSummaryWhenProvided(): void
    {
        $request = new MtfRunRequestDto(symbols: ['BTCUSDT']);

        $generator = $this->createGenerator([
            [
                'summary' => [
                    'status' => 'maintenance',
                    'timestamp' => '2024-03-01 08:00:00',
                    'message' => 'Execution deferred',
                ],
            ],
            [
                'symbol' => 'FINAL',
                'result' => [
                    'status' => 'maintenance',
                    'timestamp' => '2024-03-01 08:00:00',
                    'message' => 'Execution deferred',
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
        $this->assertSame('Execution deferred', $response->message);
        $this->assertSame(0, $response->symbolsProcessed);
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
