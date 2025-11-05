<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Provider\Dto\OrderDto;
use App\MtfValidator\Service\Application\PositionsSnapshot;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Service\SymbolProcessor;
use App\MtfValidator\Service\Timeframe\CascadeTimelineService;
use App\Repository\MtfSwitchRepository;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class SymbolProcessorTest extends TestCase
{
    private SymbolProcessor $symbolProcessor;
    private CascadeTimelineService $cascadeService;
    private LoggerInterface $logger;
    private ClockInterface $clock;
    private MtfSwitchRepository $switchRepository;
    private PositionsSnapshot $emptySnapshot;

    protected function setUp(): void
    {
        $this->cascadeService = $this->createMock(CascadeTimelineService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->switchRepository = $this->createMock(MtfSwitchRepository::class);
        $this->emptySnapshot = new PositionsSnapshot([], [], []);

        $this->switchRepository->method('isSymbolSwitchOn')->willReturn(true);
        $this->switchRepository->method('isSymbolTimeframeSwitchOn')->willReturn(true);

        $this->symbolProcessor = new SymbolProcessor(
            $this->cascadeService,
            $this->logger,
            $this->clock,
            $this->switchRepository,
        );
    }

    public function testProcessSymbolSuccess(): void
    {
        $symbol = 'BTCUSDT';
        $runId = Uuid::uuid4();
        $dto = new MtfRunDto(symbols: [$symbol]);
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $expected = new SymbolResultDto(
            symbol: $symbol,
            status: 'SUCCESS',
            executionTf: '1m',
            signalSide: 'LONG'
        );

        $this->cascadeService->expects($this->once())
            ->method('execute')
            ->with($symbol, $runId, $dto, $now, null, $this->isType('array'))
            ->willReturn($expected);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('[Symbol Processor] Processing symbol', $this->isType('array'));

        $result = $this->symbolProcessor->processSymbol($symbol, $runId, $dto, $now, $this->emptySnapshot);

        $this->assertSame($expected, $result);
    }

    public function testProcessSymbolHandlesException(): void
    {
        $symbol = 'BTCUSDT';
        $runId = Uuid::uuid4();
        $dto = new MtfRunDto(symbols: [$symbol]);
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $this->cascadeService->expects($this->once())
            ->method('execute')
            ->willThrowException(new \RuntimeException('cascade error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('[Symbol Processor] Error processing symbol', $this->callback(function (array $context) use ($symbol) {
                return $context['symbol'] === $symbol && $context['error'] === 'cascade error';
            }));

        $result = $this->symbolProcessor->processSymbol($symbol, $runId, $dto, $now, $this->emptySnapshot);

        $this->assertEquals('ERROR', $result->status);
        $this->assertIsArray($result->error);
    }

    public function testProcessSymbolOverridesTimeframeWithAdjustments(): void
    {
        $symbol = 'ETHUSDT';
        $runId = Uuid::uuid4();
        $dto = new MtfRunDto(symbols: [$symbol]);
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $snapshot = new PositionsSnapshot([], [], [
            $symbol => true,
        ]);

        $expected = new SymbolResultDto(symbol: $symbol, status: 'SUCCESS');

        $this->cascadeService->expects($this->once())
            ->method('execute')
            ->with($symbol, $runId, $dto, $now, '1m', $this->isType('array'))
            ->willReturn($expected);

        $result = $this->symbolProcessor->processSymbol($symbol, $runId, $dto, $now, $snapshot);

        $this->assertSame($expected, $result);
    }

    public function testProcessSymbolOverridesTimeframeWithOpenOrders(): void
    {
        $symbol = 'LTCUSDT';
        $runId = Uuid::uuid4();
        $dto = new MtfRunDto(symbols: [$symbol]);
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $order = $this->createMock(OrderDto::class);
        $snapshot = new PositionsSnapshot([], [
            $symbol => [$order],
        ], []);

        $expected = new SymbolResultDto(symbol: $symbol, status: 'READY');

        $this->cascadeService->expects($this->once())
            ->method('execute')
            ->with($symbol, $runId, $dto, $now, '15m', $this->isType('array'))
            ->willReturn($expected);

        $result = $this->symbolProcessor->processSymbol($symbol, $runId, $dto, $now, $snapshot);

        $this->assertSame($expected, $result);
    }

    public function testProcessSymbolRespectsCurrentTimeframe(): void
    {
        $symbol = 'BNBUSDT';
        $runId = Uuid::uuid4();
        $dto = new MtfRunDto(symbols: [$symbol], currentTf: '5m');
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');

        $expected = new SymbolResultDto(symbol: $symbol, status: 'SUCCESS');

        $this->cascadeService->expects($this->once())
            ->method('execute')
            ->with($symbol, $runId, $dto, $now, '5m', $this->isType('array'))
            ->willReturn($expected);

        $result = $this->symbolProcessor->processSymbol($symbol, $runId, $dto, $now, $this->emptySnapshot);

        $this->assertSame($expected, $result);
    }
}
