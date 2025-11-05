<?php

declare(strict_types=1);

namespace App\Tests\Contract\MtfValidator;

use App\Contract\MtfValidator\MtfValidatorInterface;
use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\MtfRunResponseDto;
use PHPUnit\Framework\TestCase;

/**
 * Tests pour l'interface MtfValidatorInterface
 */
class MtfValidatorInterfaceTest extends TestCase
{
    public function testMtfValidatorInterface(): void
    {
        // Test que l'interface existe et a les bonnes méthodes
        $this->assertTrue(interface_exists(MtfValidatorInterface::class));
        
        $reflection = new \ReflectionClass(MtfValidatorInterface::class);
        $this->assertTrue($reflection->hasMethod('run'));
        $this->assertTrue($reflection->hasMethod('healthCheck'));
        $this->assertTrue($reflection->hasMethod('getServiceName'));
    }

    public function testMtfRunRequestDto(): void
    {
        $request = new MtfRunRequestDto(
            symbols: ['BTCUSDT', 'ETHUSDT'],
            dryRun: true,
            forceRun: false,
            currentTf: '1h',
            forceTimeframeCheck: false,
            lockPerSymbol: false,
            userId: 'user123',
            ipAddress: '192.168.1.1'
        );

        $this->assertEquals(['BTCUSDT', 'ETHUSDT'], $request->symbols);
        $this->assertTrue($request->dryRun);
        $this->assertFalse($request->forceRun);
        $this->assertEquals('1h', $request->currentTf);
        $this->assertEquals('user123', $request->userId);
        $this->assertEquals('192.168.1.1', $request->ipAddress);
    }

    public function testMtfRunRequestDtoFromArray(): void
    {
        $data = [
            'symbols' => ['BTCUSDT'],
            'dry_run' => true,
            'force_run' => false,
            'current_tf' => '1h',
            'force_timeframe_check' => false,
            'lock_per_symbol' => false,
            'user_id' => 'user123',
            'ip_address' => '192.168.1.1'
        ];

        $request = MtfRunRequestDto::fromArray($data);

        $this->assertEquals(['BTCUSDT'], $request->symbols);
        $this->assertTrue($request->dryRun);
        $this->assertFalse($request->forceRun);
        $this->assertEquals('1h', $request->currentTf);
    }

    public function testMtfRunResponseDto(): void
    {
        $response = new MtfRunResponseDto(
            runId: 'test-run-123',
            status: 'success',
            executionTimeSeconds: 1.5,
            symbolsRequested: 2,
            symbolsProcessed: 2,
            symbolsSuccessful: 2,
            symbolsFailed: 0,
            symbolsSkipped: 0,
            successRate: 100.0,
            contractsProcessed: 2,
            lastSuccessfulTimeframe: '1m',
            results: ['BTCUSDT' => ['status' => 'success']],
            errors: [],
            timestamp: new \DateTimeImmutable(),
            message: 'Test completed'
        );

        $this->assertEquals('test-run-123', $response->runId);
        $this->assertEquals('success', $response->status);
        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->isError());
        $this->assertFalse($response->isPartialSuccess());
        $this->assertEquals(2, $response->getTotalSymbols());
        $this->assertEquals(2, $response->getProcessedSymbols());
    }

    public function testMtfRunResponseDtoStatusMethods(): void
    {
        $successResponse = new MtfRunResponseDto(
            runId: 'test-1',
            status: 'success',
            executionTimeSeconds: 1.0,
            symbolsRequested: 1,
            symbolsProcessed: 1,
            symbolsSuccessful: 1,
            symbolsFailed: 0,
            symbolsSkipped: 0,
            successRate: 100.0,
            contractsProcessed: 1,
            lastSuccessfulTimeframe: '1m',
            results: [],
            errors: [],
            timestamp: new \DateTimeImmutable()
        );

        $partialResponse = new MtfRunResponseDto(
            runId: 'test-2',
            status: 'partial_success',
            executionTimeSeconds: 1.0,
            symbolsRequested: 2,
            symbolsProcessed: 2,
            symbolsSuccessful: 1,
            symbolsFailed: 1,
            symbolsSkipped: 0,
            successRate: 50.0,
            contractsProcessed: 2,
            lastSuccessfulTimeframe: '5m',
            results: [],
            errors: [],
            timestamp: new \DateTimeImmutable()
        );

        $errorResponse = new MtfRunResponseDto(
            runId: 'test-3',
            status: 'error',
            executionTimeSeconds: 1.0,
            symbolsRequested: 1,
            symbolsProcessed: 0,
            symbolsSuccessful: 0,
            symbolsFailed: 1,
            symbolsSkipped: 0,
            successRate: 0.0,
            contractsProcessed: 0,
            lastSuccessfulTimeframe: null,
            results: [],
            errors: [],
            timestamp: new \DateTimeImmutable()
        );

        $this->assertTrue($successResponse->isSuccess());
        $this->assertFalse($successResponse->isError());
        $this->assertFalse($successResponse->isPartialSuccess());

        $this->assertFalse($partialResponse->isSuccess());
        $this->assertFalse($partialResponse->isError());
        $this->assertTrue($partialResponse->isPartialSuccess());

        $this->assertFalse($errorResponse->isSuccess());
        $this->assertTrue($errorResponse->isError());
        $this->assertFalse($errorResponse->isPartialSuccess());
    }
}
