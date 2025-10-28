<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\Contract\Runtime\FeatureSwitchInterface;
use App\Contract\Runtime\LockManagerInterface;
use App\MtfValidator\Service\MtfRunService;
use App\MtfValidator\Service\Runner\MtfRunOrchestrator;
use App\MtfValidator\Service\SymbolProcessor;
use App\MtfValidator\Service\TradingDecisionHandler;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class MtfRunIntegrationTest extends TestCase
{
    private MtfRunService $mtfRunService;
    private MtfRunOrchestrator $orchestrator;
    private LockManagerInterface $lockManager;
    private FeatureSwitchInterface $featureSwitch;
    private AuditLoggerInterface $auditLogger;
    private SymbolProcessor $symbolProcessor;
    private TradingDecisionHandler $tradingDecisionHandler;
    private LoggerInterface $logger;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->featureSwitch = $this->createMock(FeatureSwitchInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->symbolProcessor = $this->createMock(SymbolProcessor::class);
        $this->tradingDecisionHandler = $this->createMock(TradingDecisionHandler::class);
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

        $this->mtfRunService = new MtfRunService($this->orchestrator, $this->logger);
    }

    public function testFullExecutionFlow(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT', 'ETHUSDT']);
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        // Setup mocks
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

        $this->lockManager->expects($this->once())
            ->method('releaseLock')
            ->with('mtf_execution');

        $this->symbolProcessor->expects($this->exactly(2))
            ->method('processSymbol')
            ->willReturnCallback(function ($symbol) {
                return new \App\MtfValidator\Service\Dto\SymbolResultDto(
                    $symbol,
                    'SUCCESS',
                    '1m',
                    'BUY'
                );
            });

        $this->tradingDecisionHandler->expects($this->exactly(2))
            ->method('handleTradingDecision')
            ->willReturnCallback(function ($symbolResult) {
                return $symbolResult;
            });

        $this->auditLogger->expects($this->once())
            ->method('logAction')
            ->with('MTF_RUN_COMPLETED', 'MTF_RUN', $this->isType('string'), $this->isType('array'));

        // Execute
        $result = iterator_to_array($this->mtfRunService->run($dto));

        // Assertions
        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('results', $result);
        $this->assertCount(2, $result['results']);
    }

    public function testExecutionWithGlobalSwitchOff(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT'], forceRun: false);

        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->with('mtf_global_switch')
            ->willReturn(false);

        $result = iterator_to_array($this->mtfRunService->run($dto));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals('global_switch_off', $result['summary']['status']);
    }

    public function testExecutionWithLockFailure(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->willReturn(false);

        $result = iterator_to_array($this->mtfRunService->run($dto));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals('lock_acquisition_failed', $result['summary']['status']);
    }

    public function testExecutionWithDryRun(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT'], dryRun: true);
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

        $this->lockManager->expects($this->once())
            ->method('releaseLock');

        $this->symbolProcessor->expects($this->once())
            ->method('processSymbol')
            ->willReturn(new \App\MtfValidator\Service\Dto\SymbolResultDto('BTCUSDT', 'SUCCESS'));

        // Trading decision handler should not be called in dry run
        $this->tradingDecisionHandler->expects($this->never())
            ->method('handleTradingDecision');

        $result = iterator_to_array($this->mtfRunService->run($dto));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertTrue($result['summary']['dry_run']);
    }

    public function testExecutionWithForceRun(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT'], forceRun: true);
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        // Force run should bypass global switch check
        $this->featureSwitch->expects($this->never())
            ->method('isEnabled');

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('releaseLock');

        $this->symbolProcessor->expects($this->once())
            ->method('processSymbol')
            ->willReturn(new \App\MtfValidator\Service\Dto\SymbolResultDto('BTCUSDT', 'SUCCESS'));

        $this->tradingDecisionHandler->expects($this->once())
            ->method('handleTradingDecision')
            ->willReturn(new \App\MtfValidator\Service\Dto\SymbolResultDto('BTCUSDT', 'SUCCESS'));

        $result = iterator_to_array($this->mtfRunService->run($dto));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertTrue($result['summary']['force_run']);
    }

    public function testExecutionWithLockPerSymbol(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT'], lockPerSymbol: true);
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $this->clock->expects($this->once())
            ->method('now')
            ->willReturn($now);

        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        // Should use symbol-specific lock key
        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->with('mtf_execution:BTCUSDT', 600, 3, 100)
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('releaseLock')
            ->with('mtf_execution:BTCUSDT');

        $this->symbolProcessor->expects($this->once())
            ->method('processSymbol')
            ->willReturn(new \App\MtfValidator\Service\Dto\SymbolResultDto('BTCUSDT', 'SUCCESS'));

        $this->tradingDecisionHandler->expects($this->once())
            ->method('handleTradingDecision')
            ->willReturn(new \App\MtfValidator\Service\Dto\SymbolResultDto('BTCUSDT', 'SUCCESS'));

        $result = iterator_to_array($this->mtfRunService->run($dto));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
    }

    public function testExecutionWithEmptySymbols(): void
    {
        $dto = new MtfRunDto(symbols: []);

        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('releaseLock');

        $result = iterator_to_array($this->mtfRunService->run($dto));

        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals('no_active_symbols', $result['summary']['status']);
    }

    public function testExecutionWithException(): void
    {
        $dto = new MtfRunDto(symbols: ['BTCUSDT']);

        $this->featureSwitch->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $this->lockManager->expects($this->once())
            ->method('acquireLockWithRetry')
            ->willThrowException(new \RuntimeException('Lock service error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Lock service error');

        iterator_to_array($this->mtfRunService->run($dto));
    }
}
