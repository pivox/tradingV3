<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service\Runner;

use App\Config\MtfValidationConfig;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\Contract\Runtime\FeatureSwitchInterface;
use App\Contract\Runtime\LockManagerInterface;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Service\Runner\MtfRunOrchestrator;
use App\MtfValidator\Service\SymbolProcessor;
use App\MtfValidator\Service\TradingDecisionHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class MtfRunOrchestratorTest extends TestCase
{
    private MtfRunOrchestrator $orchestrator;
    /** @var SymbolProcessor&MockObject */
    private SymbolProcessor $symbolProcessor;
    /** @var TradingDecisionHandler&MockObject */
    private TradingDecisionHandler $tradingDecisionHandler;
    /** @var LockManagerInterface&MockObject */
    private LockManagerInterface $lockManager;
    /** @var AuditLoggerInterface&MockObject */
    private AuditLoggerInterface $auditLogger;
    /** @var FeatureSwitchInterface&MockObject */
    private FeatureSwitchInterface $featureSwitch;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $orderJourneyLogger;
    /** @var ClockInterface&MockObject */
    private ClockInterface $clock;
    /** @var MtfValidationConfig&MockObject */
    private MtfValidationConfig $mtfConfig;

    protected function setUp(): void
    {
        $this->symbolProcessor = $this->createMock(SymbolProcessor::class);
        $this->tradingDecisionHandler = $this->createMock(TradingDecisionHandler::class);
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->featureSwitch = $this->createMock(FeatureSwitchInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->orderJourneyLogger = $this->createMock(LoggerInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->mtfConfig = $this->createMock(MtfValidationConfig::class);
        $this->mtfConfig->method('getConfig')->willReturn([
            'validation' => ['start_from_timeframe' => '4h'],
        ]);

        $this->orchestrator = new MtfRunOrchestrator(
            $this->symbolProcessor,
            $this->tradingDecisionHandler,
            $this->lockManager,
            $this->auditLogger,
            $this->featureSwitch,
            $this->logger,
            $this->clock,
            $this->mtfConfig,
            $this->orderJourneyLogger
        );
    }

    public function testExecuteSuccessBuildsSummaryAndReturn(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);
        $runId = Uuid::uuid4();
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $this->clock->method('now')->willReturn($now);

        $this->featureSwitch->expects($this->once())
            ->method('setDefaultState')
            ->with('mtf_global_switch', true);
        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->with('mtf_global_switch')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->with('mtf_execution', 600, 3, 100)
            ->willReturn(true);
        $this->lockManager->expects($this->once())
            ->method('releaseLock')
            ->with('mtf_execution');

        $symbolResult = new SymbolResultDto('BTCUSDT', 'SUCCESS', '1m', signalSide: 'BUY');
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

        $generator = $this->orchestrator->execute($dto, $runId);
        $yielded = iterator_to_array($generator);
        $final = $generator->getReturn();

        $this->assertNotEmpty($yielded);
        $this->assertSame('FINAL', $yielded[array_key_last($yielded)]['symbol']);
        $this->assertArrayHasKey('summary', $final);
        $this->assertArrayHasKey('results', $final);
        $this->assertSame(1, $final['summary']['symbols_processed']);
        $this->assertSame(1, $final['summary']['symbols_successful']);
    }

    public function testExecuteReturnsBlockedWhenGlobalSwitchOff(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);
        $runId = Uuid::uuid4();

        $this->featureSwitch->expects($this->once())
            ->method('setDefaultState')
            ->with('mtf_global_switch', true);
        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(false);

        $generator = $this->orchestrator->execute($dto, $runId);
        iterator_to_array($generator);
        $final = $generator->getReturn();

        $this->assertSame('global_switch_off', $final['summary']['status']);
    }

    public function testExecuteForceRunSkipsSwitchCheck(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT'], forceRun: true);
        $runId = Uuid::uuid4();
        $now = new \DateTimeImmutable();

        $this->clock->method('now')->willReturn($now);

        $this->featureSwitch->expects($this->never())->method('isEnabled');
        $this->featureSwitch->expects($this->never())->method('setDefaultState');

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->willReturn(true);
        $this->lockManager->expects($this->once())->method('releaseLock');

        $symbolResult = new SymbolResultDto('BTCUSDT', 'SUCCESS');
        $this->symbolProcessor->expects($this->once())
            ->method('processSymbol')
            ->willReturn($symbolResult);
        $this->tradingDecisionHandler->expects($this->once())
            ->method('handleTradingDecision')
            ->willReturn($symbolResult);

        $generator = $this->orchestrator->execute($dto, $runId);
        iterator_to_array($generator);
        $final = $generator->getReturn();

        $this->assertSame('completed', $final['summary']['status']);
    }

    public function testExecuteStopsWhenLockNotAcquired(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);
        $runId = Uuid::uuid4();

        $this->featureSwitch->expects($this->once())
            ->method('setDefaultState')
            ->with('mtf_global_switch', true);
        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->willReturn(false);

        $generator = $this->orchestrator->execute($dto, $runId);
        iterator_to_array($generator);
        $final = $generator->getReturn();

        $this->assertSame('lock_acquisition_failed', $final['summary']['status']);
    }

    public function testExecuteReturnsNoActiveSymbolsWhenListEmpty(): void
    {
        $dto = new MtfRunDto(symbols: []);
        $runId = Uuid::uuid4();

        $this->featureSwitch->expects($this->once())
            ->method('setDefaultState')
            ->with('mtf_global_switch', true);
        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->willReturn(true);
        $this->lockManager->expects($this->once())
            ->method('releaseLock')
            ->with('mtf_execution');

        $generator = $this->orchestrator->execute($dto, $runId);
        iterator_to_array($generator);
        $final = $generator->getReturn();

        $this->assertSame('no_active_symbols', $final['summary']['status']);
    }

    public function testExecuteWithDryRunStillTriggersDecisionHandler(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT'], dryRun: true);
        $runId = Uuid::uuid4();
        $now = new \DateTimeImmutable();

        $this->clock->method('now')->willReturn($now);

        $this->featureSwitch->expects($this->once())
            ->method('setDefaultState')
            ->with('mtf_global_switch', true);
        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $this->lockManager->expects($this->once())->method('acquireLockWithRetry')->willReturn(true);
        $this->lockManager->expects($this->once())->method('releaseLock');

        $symbolResult = new SymbolResultDto('BTCUSDT', 'SUCCESS');
        $this->symbolProcessor->expects($this->once())
            ->method('processSymbol')
            ->willReturn($symbolResult);
        $this->tradingDecisionHandler->expects($this->once())
            ->method('handleTradingDecision')
            ->with($symbolResult, $dto)
            ->willReturn($symbolResult);

        $generator = $this->orchestrator->execute($dto, $runId);
        iterator_to_array($generator);
        $final = $generator->getReturn();

        $this->assertSame('completed', $final['summary']['status']);
    }

    public function testExecuteUsesSymbolSpecificLockWhenRequested(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT'], lockPerSymbol: true);
        $runId = Uuid::uuid4();
        $now = new \DateTimeImmutable();

        $this->clock->method('now')->willReturn($now);

        $this->featureSwitch->expects($this->once())
            ->method('setDefaultState')
            ->with('mtf_global_switch', true);
        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->with('mtf_execution:BTCUSDT', 600, 3, 100)
            ->willReturn(true);
        $this->lockManager->expects($this->once())
            ->method('releaseLock')
            ->with('mtf_execution:BTCUSDT');

        $symbolResult = new SymbolResultDto('BTCUSDT', 'SUCCESS');
        $this->symbolProcessor->expects($this->once())
            ->method('processSymbol')
            ->willReturn($symbolResult);
        $this->tradingDecisionHandler->expects($this->once())
            ->method('handleTradingDecision')
            ->willReturn($symbolResult);

        $generator = $this->orchestrator->execute($dto, $runId);
        iterator_to_array($generator);
        $final = $generator->getReturn();

        $this->assertSame('completed', $final['summary']['status']);
    }
}
