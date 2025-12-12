<?php

declare(strict_types=1);

namespace App\Tests\Contract\MtfValidator;

use App\Contract\MtfValidator\Dto\TimeframeResultDto;
use App\Contract\MtfValidator\Dto\ValidationContextDto;
use PHPUnit\Framework\TestCase;

class TimeframeContractsTest extends TestCase
{
    public function testTimeframeResultDto(): void
    {
        $result = new TimeframeResultDto(
            timeframe: '1h',
            status: 'VALID',
            signalSide: 'LONG',
            klineTime: '2024-01-01 12:00:00',
            currentPrice: 50000.0,
            atr: 1000.0,
            indicatorContext: ['ema' => 49000.0],
            conditionsLong: ['ema_above' => true],
            conditionsShort: ['ema_below' => false],
            failedConditionsLong: [],
            failedConditionsShort: [],
            reason: null,
            error: null
        );

        $this->assertEquals('1h', $result->timeframe);
        $this->assertEquals('VALID', $result->status);
        $this->assertEquals('LONG', $result->signalSide);
        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isError());
        $this->assertFalse($result->isSkipped());
        $this->assertFalse($result->isInvalid());
    }

    public function testTimeframeResultDtoStatusMethods(): void
    {
        $validResult = new TimeframeResultDto(
            timeframe: '1h',
            status: 'VALID',
            signalSide: 'LONG'
        );

        $errorResult = new TimeframeResultDto(
            timeframe: '1h',
            status: 'ERROR',
            signalSide: null,
            reason: 'Exception occurred'
        );

        $skippedResult = new TimeframeResultDto(
            timeframe: '1h',
            status: 'SKIPPED',
            signalSide: null,
            reason: 'Too recent'
        );

        $invalidResult = new TimeframeResultDto(
            timeframe: '1h',
            status: 'INVALID',
            signalSide: 'NONE',
            reason: 'Validation failed'
        );

        $this->assertTrue($validResult->isSuccess());
        $this->assertFalse($validResult->isError());
        $this->assertFalse($validResult->isSkipped());
        $this->assertFalse($validResult->isInvalid());

        $this->assertFalse($errorResult->isSuccess());
        $this->assertTrue($errorResult->isError());
        $this->assertFalse($errorResult->isSkipped());
        $this->assertFalse($errorResult->isInvalid());

        $this->assertFalse($skippedResult->isSuccess());
        $this->assertFalse($skippedResult->isError());
        $this->assertTrue($skippedResult->isSkipped());
        $this->assertFalse($skippedResult->isInvalid());

        $this->assertFalse($invalidResult->isSuccess());
        $this->assertFalse($invalidResult->isError());
        $this->assertFalse($invalidResult->isSkipped());
        $this->assertTrue($invalidResult->isInvalid());
    }

    public function testValidationContextDto(): void
    {
        $context = new ValidationContextDto(
            runId: 'test-run-123',
            now: new \DateTimeImmutable('2024-01-01 12:00:00'),
            collector: ['1h' => ['signal' => 'LONG']],
            forceTimeframeCheck: true,
            forceRun: false,
            userId: 'user123',
            ipAddress: '192.168.1.1'
        );

        $this->assertEquals('test-run-123', $context->runId);
        $this->assertEquals('2024-01-01 12:00:00', $context->now->format('Y-m-d H:i:s'));
        $this->assertEquals(['1h' => ['signal' => 'LONG']], $context->collector);
        $this->assertTrue($context->forceTimeframeCheck);
        $this->assertFalse($context->forceRun);
        $this->assertEquals('user123', $context->userId);
        $this->assertEquals('192.168.1.1', $context->ipAddress);
    }

    public function testValidationContextDtoCreate(): void
    {
        $context = ValidationContextDto::create(
            runId: 'test-run-456',
            now: new \DateTimeImmutable('2024-01-01 15:00:00'),
            collector: ['4h' => ['signal' => 'SHORT']],
            forceTimeframeCheck: false,
            forceRun: true,
            userId: 'user456',
            ipAddress: '192.168.1.2'
        );

        $this->assertEquals('test-run-456', $context->runId);
        $this->assertEquals('2024-01-01 15:00:00', $context->now->format('Y-m-d H:i:s'));
        $this->assertEquals(['4h' => ['signal' => 'SHORT']], $context->collector);
        $this->assertFalse($context->forceTimeframeCheck);
        $this->assertTrue($context->forceRun);
        $this->assertEquals('user456', $context->userId);
        $this->assertEquals('192.168.1.2', $context->ipAddress);
    }
}
