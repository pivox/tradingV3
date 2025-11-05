<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service\Timeframe;

use App\Config\MtfValidationConfig;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\MtfValidator\Dto\TimeframeResultDto;
use App\Contract\MtfValidator\Dto\ValidationContextDto;
use App\Contract\MtfValidator\TimeframeProcessorInterface;
use App\MtfValidator\Service\Timeframe\CascadeTimelineService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Yaml\Yaml;

final class CascadeTimelineServiceTest extends TestCase
{
    private string $configFile;

    protected function setUp(): void
    {
        $config = [
            'mtf_validation' => [
                'validation' => [
                    'start_from_timeframe' => '4h',
                ],
            ],
        ];

        $this->configFile = tempnam(sys_get_temp_dir(), 'mtf_cfg_');
        file_put_contents($this->configFile, Yaml::dump($config));
    }

    protected function tearDown(): void
    {
        @unlink($this->configFile);
    }

    public function testCascadeRunsSequenceUntilLowestTimeframe(): void
    {
        $processors = [
            $this->createProcessor('4h', 'LONG'),
            $this->createProcessor('1h', 'LONG'),
            $this->createProcessor('15m', 'LONG'),
            $this->createProcessor('5m', 'LONG'),
            $this->createProcessor('1m', 'LONG', currentPrice: 42.1, atr: 1.5),
        ];

        $service = $this->createService($processors);
        $result = $service->execute(
            'BTCUSDT',
            Uuid::uuid4(),
            new MtfRunDto(symbols: ['BTCUSDT']),
            new \DateTimeImmutable('2024-01-01 12:00:00'),
        );

        self::assertSame('READY', $result->status);
        self::assertSame('1m', $result->executionTf);
        self::assertSame('LONG', $result->signalSide);
        self::assertSame(42.1, $result->currentPrice);
        self::assertSame(1.5, $result->atr);
        self::assertArrayHasKey('timeframes', $result->context);
        self::assertCount(5, $result->context['timeframes']);
    }

    public function testCascadeStopsOnInvalidResult(): void
    {
        $valid = $this->createProcessor('4h', 'LONG');
        $invalid = $this->createProcessor('1h', 'NONE', status: 'INVALID', reason: 'failed');

        $service = $this->createService([$valid, $invalid]);
        $result = $service->execute(
            'ETHUSDT',
            Uuid::uuid4(),
            new MtfRunDto(symbols: ['ETHUSDT']),
            new \DateTimeImmutable('2024-01-01T00:00:00Z'),
        );

        self::assertSame('INVALID', $result->status);
        self::assertSame('1h', $result->failedTimeframe);
        self::assertSame('failed', $result->error['message'] ?? null);
    }

    public function testCascadeDetectsAlignmentFailure(): void
    {
        $parent = $this->createProcessor('4h', 'LONG');
        $child = $this->createProcessor('1h', 'SHORT');

        $service = $this->createService([$parent, $child]);
        $result = $service->execute(
            'BNBUSDT',
            Uuid::uuid4(),
            new MtfRunDto(symbols: ['BNBUSDT']),
            new \DateTimeImmutable('2024-01-01 08:00:00'),
        );

        self::assertSame('INVALID', $result->status);
        self::assertSame('1h', $result->failedTimeframe);
        self::assertSame('ALIGNMENT_FAILED', $result->error['message'] ?? null);
    }

    /**
     * @param list<TimeframeProcessorInterface> $processors
     */
    private function createService(array $processors): CascadeTimelineService
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('debug');
        $logger->method('info');
        $logger->method('warning');

        return new CascadeTimelineService(
            $processors,
            new MtfValidationConfig($this->configFile),
            $logger,
            $logger,
        );
    }

    private function createProcessor(
        string $timeframe,
        string $side,
        string $status = 'VALID',
        ?string $reason = null,
        ?float $currentPrice = null,
        ?float $atr = null
    ): TimeframeProcessorInterface {
        return new class($timeframe, $side, $status, $reason, $currentPrice, $atr) implements TimeframeProcessorInterface {
            private bool $called = false;

            public function __construct(
                private readonly string $timeframe,
                private readonly string $side,
                private readonly string $status,
                private readonly ?string $reason,
                private readonly ?float $price,
                private readonly ?float $atr,
            ) {
            }

            public function processTimeframe(string $symbol, ValidationContextDto $context): TimeframeResultDto
            {
                if ($this->called) {
                    throw new \RuntimeException('Processor called twice for ' . $this->timeframe);
                }
                $this->called = true;

                return new TimeframeResultDto(
                    timeframe: $this->timeframe,
                    status: $this->status,
                    signalSide: $this->side,
                    reason: $this->reason,
                    currentPrice: $this->price,
                    atr: $this->atr,
                );
            }

            public function getTimeframeValue(): string
            {
                return $this->timeframe;
            }

            public function canProcess(string $timeframe): bool
            {
                return strtolower($timeframe) === strtolower($this->timeframe);
            }

            public function checkAlignment(array $currentResult, array $parentResult, string $parentTimeframe): array
            {
                $childSide = strtoupper((string)($currentResult['signal_side'] ?? 'NONE'));
                $parentSide = strtoupper((string)($parentResult['signal_side'] ?? 'NONE'));

                if ($childSide !== $parentSide) {
                    return ['status' => 'INVALID', 'reason' => 'ALIGNMENT_FAILED'];
                }

                return ['status' => 'ALIGNED'];
            }
        };
    }
}
