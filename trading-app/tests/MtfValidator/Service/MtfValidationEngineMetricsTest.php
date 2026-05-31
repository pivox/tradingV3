<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\MtfValidator\Service\MtfValidationEngineMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(MtfValidationEngineMetrics::class)]
final class MtfValidationEngineMetricsTest extends TestCase
{
    public function testRecordsFallbackCounterAndAlertsAtThreshold(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var array<int,array{level:mixed,message:string,context:array<string,mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
        $metrics = new MtfValidationEngineMetrics($logger, fallbackAlertThreshold: 2);

        self::assertSame(1, $metrics->recordConditionRegistryFallback(
            symbol: 'BTCUSDT',
            timeframe: '5m',
            phase: 'context',
            mode: 'scalper',
            error: new \RuntimeException('first failure'),
        ));
        self::assertSame(2, $metrics->recordConditionRegistryFallback(
            symbol: 'ETHUSDT',
            timeframe: '1m',
            phase: 'execution',
            mode: 'scalper',
            error: new \LogicException('second failure'),
        ));

        self::assertSame(2, $metrics->snapshot()[MtfValidationEngineMetrics::CONDITION_REGISTRY_FALLBACK_COUNT]);
        self::assertCount(2, array_filter(
            $logger->records,
            static fn (array $record): bool => $record['level'] === 'warning'
                && ($record['context']['metric'] ?? null) === MtfValidationEngineMetrics::CONDITION_REGISTRY_FALLBACK_COUNT,
        ));
        self::assertCount(1, array_filter(
            $logger->records,
            static fn (array $record): bool => $record['level'] === 'critical'
                && ($record['context']['alert'] ?? null) === MtfValidationEngineMetrics::CONDITION_REGISTRY_FALLBACK_COUNT,
        ));

        $metrics->reset();
        self::assertSame(0, $metrics->snapshot()[MtfValidationEngineMetrics::CONDITION_REGISTRY_FALLBACK_COUNT]);
    }
}
