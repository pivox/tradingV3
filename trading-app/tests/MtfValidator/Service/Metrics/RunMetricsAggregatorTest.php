<?php

declare(strict_types=1);

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Service\Metrics\RunMetricsAggregator;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Types\Side;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class RunMetricsAggregatorTest extends TestCase
{
    public function testCompleteRunAggregatesMetrics(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger
            ->expects($this->once())
            ->method('logAction')
            ->with(
                'MTF_RUN_COMPLETED',
                'MTF_RUN',
                $this->anything(),
                $this->arrayHasKey('symbols_processed')
            );

        $aggregator = new RunMetricsAggregator($auditLogger, new NullLogger());
        $aggregator->startRun(Uuid::uuid4(), new MtfRunDto(symbols: ['BTCUSDT', 'ETHUSDT']));

        $aggregator->recordSymbolResult(new SymbolResultDto('BTCUSDT', 'SUCCESS'));
        $aggregator->recordSymbolResult(new SymbolResultDto('ETHUSDT', 'SKIPPED'));
        $aggregator->recordSymbolResult(new SymbolResultDto('XRPUSDT', 'ERROR'));

        $summary = $aggregator->completeRun(1.234);

        $this->assertSame(3, $summary->symbolsProcessed);
        $this->assertSame(1, $summary->symbolsSuccessful);
        $this->assertSame(1, $summary->symbolsSkipped);
        $this->assertSame(1, $summary->symbolsFailed);
        $this->assertSame('completed', $summary->status);

        $results = $aggregator->getResults();
        $this->assertArrayHasKey('BTCUSDT', $results);
    }

    public function testAuditHelpersForwardPayload(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger
            ->expects($this->exactly(2))
            ->method('logAction')
            ->withConsecutive(
                [
                    'TRADE_ENTRY_SIMULATED',
                    'TRADE_ENTRY',
                    'BTCUSDT',
                    $this->arrayHasKey('status'),
                ],
                [
                    'TRADE_ENTRY_FAILED',
                    'TRADE_ENTRY',
                    'BTCUSDT',
                    $this->arrayHasKey('error'),
                ],
            );

        $aggregator = new RunMetricsAggregator($auditLogger, new NullLogger());

        $symbolResult = new SymbolResultDto(
            symbol: 'BTCUSDT',
            status: 'READY',
            executionTf: '1m',
            signalSide: 'long'
        );
        $request = new TradeEntryRequest('BTCUSDT', Side::Long);
        $execution = new ExecutionResult('cid', 'ex', 'simulated', []);

        $aggregator->recordAuditTradeEntrySuccess(true, $symbolResult, $request, $execution);
        $aggregator->recordAuditTradeEntryFailure($symbolResult, new RuntimeException('failure'));
    }
}

