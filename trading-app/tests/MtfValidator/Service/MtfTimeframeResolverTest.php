<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\MtfValidator\Service\MtfTimeframeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MtfTimeframeResolver::class)]
final class MtfTimeframeResolverTest extends TestCase
{
    private MtfTimeframeResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new MtfTimeframeResolver();
    }

    public function testExplicitContextAndExecutionTimeframesKeepFirstOccurrenceOrder(): void
    {
        $config = [
            'context_timeframes' => ['4h', '1h', '4h'],
            'execution_timeframes' => ['15m', '5m', '15m'],
        ];

        self::assertSame(['4h', '1h'], $this->resolver->resolveContext($config));
        self::assertSame(['15m', '5m'], $this->resolver->resolveExecution($config));
        self::assertSame(['4h', '1h', '15m', '5m'], $this->resolver->resolveAll($config));
    }

    public function testMissingExecutionTimeframesAreDerivedFromValidationAfterContext(): void
    {
        $config = [
            'context_timeframes' => ['4h', '1h'],
            'validation' => [
                'timeframe' => [
                    '4h' => [],
                    '1h' => [],
                    '15m' => [],
                    '5m' => [],
                    '1m' => [],
                ],
            ],
        ];

        self::assertSame(
            ['15m', '5m', '1m'],
            $this->resolver->resolveExecution($config, ['4h', '1h']),
        );
    }

    public function testMissingConfigurationUsesDocumentedFinalFallbacks(): void
    {
        self::assertSame(['4h', '1h'], $this->resolver->resolveContext([]));
        self::assertSame(['15m', '5m', '1m'], $this->resolver->resolveExecution([]));
        self::assertSame(
            ['4h', '1h', '15m', '5m', '1m'],
            $this->resolver->resolveAll([]),
        );
    }

    public function testStringValuesAreNormalizedAndDuplicatesAreRemovedAcrossPhases(): void
    {
        $config = [
            'context_timeframes' => '1h',
            'execution_timeframes' => ['1h', '5m', '5m'],
        ];

        self::assertSame(['1h', '5m'], $this->resolver->resolveAll($config));
    }
}
