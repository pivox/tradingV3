<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service\Dto;

use App\Contract\MtfValidator\Dto\MtfRunRequestDto;
use App\Contract\MtfValidator\Dto\TimeframeResultDto;
use App\Contract\MtfValidator\Dto\ValidationContextDto;
use App\MtfValidator\Service\Dto\InternalMtfRunDto;
use App\MtfValidator\Service\Dto\InternalTimeframeResultDto;
use App\MtfValidator\Service\Dto\ProcessingContextDto;
use App\MtfValidator\Service\Dto\RunSummaryDto;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use PHPUnit\Framework\TestCase;

final class DtoMapperTest extends TestCase
{
    public function testInternalMtfRunDtoMapping(): void
    {
        $request = new MtfRunRequestDto(
            symbols: ['BTCUSDT'],
            dryRun: true,
            forceRun: false,
            currentTf: '1h',
            forceTimeframeCheck: true,
            skipContextValidation: true,
            lockPerSymbol: true,
            userId: 'user42',
            ipAddress: '10.0.0.1'
        );

        $internal = InternalMtfRunDto::fromContractRequest('run-123', $request);
        $this->assertSame('run-123', $internal->runId);
        $this->assertSame(['BTCUSDT'], $internal->symbols);
        $this->assertTrue($internal->dryRun);
        $this->assertTrue($internal->forceTimeframeCheck);
        $this->assertTrue($internal->lockPerSymbol);
        $this->assertSame('user42', $internal->userId);
        $this->assertSame('10.0.0.1', $internal->ipAddress);

        $array = $internal->toArray();
        $this->assertSame('run-123', $array['run_id']);
        $this->assertSame(['BTCUSDT'], $array['symbols']);
        $this->assertSame('user42', $array['user_id']);
        $this->assertSame('10.0.0.1', $array['ip_address']);
    }

    public function testInternalTimeframeResultDtoMapping(): void
    {
        $internal = new InternalTimeframeResultDto(
            timeframe: '1h',
            status: 'VALID',
            signalSide: 'LONG',
            klineTime: '2024-01-01 12:00:00',
            currentPrice: 42000.0,
            atr: 250.0,
            indicatorContext: ['macd' => ['hist' => 0.5]],
            conditionsLong: ['condA'],
            conditionsShort: [],
            failedConditionsLong: [],
            failedConditionsShort: [],
            reason: null,
            error: null,
            metadata: ['from_cache' => true]
        );

        $contract = $internal->toContractDto();
        $this->assertInstanceOf(TimeframeResultDto::class, $contract);
        $this->assertSame('1h', $contract->timeframe);
        $this->assertSame('VALID', $contract->status);
        $this->assertSame('LONG', $contract->signalSide);

        $array = $internal->toArray();
        $this->assertSame('1h', $array['timeframe']);
        $this->assertSame(42000.0, $array['current_price']);
        $this->assertSame(['macd' => ['hist' => 0.5]], $array['indicator_context']);
        $this->assertSame(['from_cache' => true], $array['metadata']);
    }

    public function testProcessingContextDtoMapping(): void
    {
        $contract = new ValidationContextDto(
            runId: 'run-456',
            now: new \DateTimeImmutable('2024-01-01 00:00:00'),
            collector: [['tf' => '4h', 'signal_side' => 'LONG']],
            forceTimeframeCheck: true,
            forceRun: false,
            skipContextValidation: true,
            userId: 'user',
            ipAddress: '127.0.0.1'
        );

        $internal = ProcessingContextDto::fromContractContext('BTCUSDT', $contract);
        $this->assertSame('BTCUSDT', $internal->symbol);
        $this->assertTrue($internal->skipContextValidation);

        $backToContract = $internal->toContractContext();
        $this->assertInstanceOf(ValidationContextDto::class, $backToContract);
        $this->assertSame('run-456', $backToContract->runId);
        $this->assertSame('127.0.0.1', $backToContract->ipAddress);
    }

    public function testSymbolResultDtoHelpers(): void
    {
        $dto = new SymbolResultDto(
            symbol: 'BTCUSDT',
            status: 'SUCCESS',
            executionTf: '1m',
            signalSide: 'LONG',
            tradingDecision: ['status' => 'ok'],
            error: null,
            context: ['trend' => 'bull'],
            currentPrice: 40000.0,
            atr: 150.0
        );

        $this->assertTrue($dto->isSuccess());
        $this->assertFalse($dto->isError());
        $this->assertFalse($dto->isSkipped());
        $this->assertTrue($dto->hasTradingDecision());

        $array = $dto->toArray();
        $this->assertSame('BTCUSDT', $array['symbol']);
        $this->assertSame('1m', $array['execution_tf']);
        $this->assertSame(['status' => 'ok'], $array['trading_decision']);
    }

    public function testRunSummaryDtoMapping(): void
    {
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');
        $summary = new RunSummaryDto(
            runId: 'run-789',
            executionTimeSeconds: 1.234,
            symbolsRequested: 2,
            symbolsProcessed: 2,
            symbolsSuccessful: 1,
            symbolsFailed: 1,
            symbolsSkipped: 0,
            successRate: 50.0,
            dryRun: false,
            forceRun: true,
            currentTf: '1h',
            timestamp: $now,
            status: 'completed'
        );

        $this->assertTrue($summary->isCompleted());
        $this->assertSame(2, $summary->getProcessedSymbols());

        $array = $summary->toArray();
        $this->assertSame('run-789', $array['run_id']);
        $this->assertSame('1h', $array['current_tf']);
        $this->assertSame('2024-01-01 12:00:00', $array['timestamp']);
    }
}
