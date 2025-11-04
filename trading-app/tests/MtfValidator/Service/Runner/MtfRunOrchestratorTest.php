<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service\Runner;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\Contract\Runtime\FeatureSwitchInterface;
use App\Contract\Runtime\LockManagerInterface;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Service\Runner\MtfRunOrchestrator;
use App\MtfValidator\Service\SymbolProcessor;
use App\MtfValidator\Service\TradingDecisionHandler;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class MtfRunOrchestratorTest extends TestCase
{
    private MtfRunOrchestrator $orchestrator;
    private SymbolProcessor $symbolProcessor;
    private TradingDecisionHandler $tradingDecisionHandler;
    private LockManagerInterface $lockManager;
    private AuditLoggerInterface $auditLogger;
    private FeatureSwitchInterface $featureSwitch;
    private LoggerInterface $logger;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        $this->symbolProcessor = $this->createMock(SymbolProcessor::class);
        $this->tradingDecisionHandler = $this->createMock(TradingDecisionHandler::class);
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->featureSwitch = $this->createMock(FeatureSwitchInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);

        $this->orchestrator = new MtfRunOrchestrator(
            $this->symbolProcessor,
            $this->tradingDecisionHandler,
            $this->lockManager,
            $this->auditLogger,
            $this->featureSwitch,
            $this->logger,
            $this->clock
        );
    }

    public function testExecuteSuccess(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);
        $runId = Uuid::uuid4();
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->with('mtf_global_switch')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->willReturn(true);

        $symbolResult = new SymbolResultDto(
            symbol: 'BTCUSDT',
            status: 'SUCCESS',
            executionTf: '1m',
            signalSide: 'BUY'
        );

        $this->symbolProcessor->expects($this->once())
            ->method('processSymbol')
            ->with('BTCUSDT', $runId, $dto, $now)
            ->willReturn($symbolResult);

        $this->tradingDecisionHandler->expects($this->once())
            ->method('handleTradingDecision')
            ->with($symbolResult, $dto)
            ->willReturn($symbolResult);

        $this->auditLogger->expects($this->once())
            ->method('logAction')
            ->with('MTF_RUN_COMPLETED', 'MTF_RUN', $runId->toString(), $this->isType('array'));

        $this->lockManager->expects($this->once())
            ->method('releaseLock')
            ->with('mtf_execution');

        $result = iterator_to_array($this->orchestrator->execute($dto, $runId));
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('results', $result);
    }

    public function testExecuteWithGlobalSwitchOff(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT'], forceRun: false);
        $runId = Uuid::uuid4();

        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->with('mtf_global_switch')
            ->willReturn(false);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('[MTF Orchestrator] Global switch OFF', $this->isType('array'));

        $result = iterator_to_array($this->orchestrator->execute($dto, $runId));
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals('global_switch_off', $result['summary']['status']);
    }

    public function testExecuteWithForceRun(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT'], forceRun: true);
        $runId = Uuid::uuid4();

        // Force run should bypass global switch check
        $this->featureSwitch->expects($this->never())
            ->method('isEnabled');

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->willReturn(true);

        $this->symbolProcessor->expects($this->once())
            ->method('processSymbol')
            ->willReturn(new SymbolResultDto('BTCUSDT', 'SUCCESS'));

        $this->tradingDecisionHandler->expects($this->once())
            ->method('handleTradingDecision')
            ->willReturn(new SymbolResultDto('BTCUSDT', 'SUCCESS'));

        $result = iterator_to_array($this->orchestrator->execute($dto, $runId));
        
        $this->assertIsArray($result);
    }

    public function testExecuteWithLockAcquisitionFailure(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);
        $runId = Uuid::uuid4();

        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->willReturn(false);

        $result = iterator_to_array($this->orchestrator->execute($dto, $runId));
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals('lock_acquisition_failed', $result['summary']['status']);
    }

    public function testExecuteWithEmptySymbols(): void
    {
        $dto = new MtfRunDto(symbols: []);
        $runId = Uuid::uuid4();

        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->willReturn(true);

        $result = iterator_to_array($this->orchestrator->execute($dto, $runId));
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals('no_active_symbols', $result['summary']['status']);
    }

    public function testExecuteWithMultipleSymbols(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT', 'ETHUSDT']);
        $runId = Uuid::uuid4();
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->willReturn(true);

        $symbolResult1 = new SymbolResultDto('BTCUSDT', 'SUCCESS');
        $symbolResult2 = new SymbolResultDto('ETHUSDT', 'SUCCESS');

        $this->symbolProcessor->expects($this->exactly(2))
            ->method('processSymbol')
            ->willReturnOnConsecutiveCalls($symbolResult1, $symbolResult2);

        $this->tradingDecisionHandler->expects($this->exactly(2))
            ->method('handleTradingDecision')
            ->willReturnOnConsecutiveCalls($symbolResult1, $symbolResult2);

        $result = iterator_to_array($this->orchestrator->execute($dto, $runId));
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('results', $result);
        $this->assertCount(2, $result['results']);
    }

    public function testExecuteWithDryRun(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT'], dryRun: true);
        $runId = Uuid::uuid4();
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->willReturn(true);

        $symbolResult = new SymbolResultDto('BTCUSDT', 'SUCCESS');

        $this->symbolProcessor->expects($this->once())
            ->method('processSymbol')
            ->willReturn($symbolResult);

        // Trading decision handler should not be called in dry run
        $this->tradingDecisionHandler->expects($this->never())
            ->method('handleTradingDecision');

        $result = iterator_to_array($this->orchestrator->execute($dto, $runId));
        
        $this->assertIsArray($result);
    }

    public function testExecuteWithLockPerSymbol(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT'], lockPerSymbol: true);
        $runId = Uuid::uuid4();

        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        // Should use symbol-specific lock key
        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->with('mtf_execution:BTCUSDT', 600, 3, 100)
            ->willReturn(true);

        $this->symbolProcessor->expects($this->once())
            ->method('processSymbol')
            ->willReturn(new SymbolResultDto('BTCUSDT', 'SUCCESS'));

        $this->tradingDecisionHandler->expects($this->once())
            ->method('handleTradingDecision')
            ->willReturn(new SymbolResultDto('BTCUSDT', 'SUCCESS'));

        $this->lockManager->expects($this->once())
            ->method('releaseLock')
            ->with('mtf_execution:BTCUSDT');

        $result = iterator_to_array($this->orchestrator->execute($dto, $runId));
        
        $this->assertIsArray($result);
    }

    public function testExecuteLogsCorrectly(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);
        $runId = Uuid::uuid4();

        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->willReturn(true);

        $this->symbolProcessor->expects($this->once())
            ->method('processSymbol')
            ->willReturn(new SymbolResultDto('BTCUSDT', 'SUCCESS'));

        $this->tradingDecisionHandler->expects($this->once())
            ->method('handleTradingDecision')
            ->willReturn(new SymbolResultDto('BTCUSDT', 'SUCCESS'));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('[MTF Orchestrator] Starting execution', $this->callback(function ($data) {
                return $data['run_id'] === $runId->toString() &&
                       $data['symbols_count'] === 1 &&
                       $data['dry_run'] === false;
            }));

        $result = iterator_to_array($this->orchestrator->execute($dto, $runId));
        
        $this->assertIsArray($result);
    }
}
