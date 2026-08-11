<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\DayTrading;

use App\TradingCore\DayTrading\DayTradingShadowRuntime;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DayTradingShadowDependencyTest extends TestCase
{
    public function testRuntimeConstructorOwnsOnlyCanonicalBoundaries(): void
    {
        $constructor = (new \ReflectionClass(DayTradingShadowRuntime::class))->getConstructor();
        self::assertNotNull($constructor);
        $types = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters(),
        );

        self::assertSame([
            'App\TradingCore\Config\EffectiveTradingConfigResolverInterface',
            'App\MtfValidator\Policy\CanonicalSetupRuleRuntime',
            'App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler',
            'App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder',
            'App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterSelector',
            'Psr\Clock\ClockInterface',
        ], $types);
    }

    public function testDayTradingNamespaceCannotImportLegacyOrPrivateExecutionLayers(): void
    {
        $root = dirname(__DIR__, 3) . '/src/TradingCore/DayTrading';
        $source = '';
        foreach (glob($root . '/*.php') ?: [] as $file) {
            $contents = file_get_contents($file);
            self::assertIsString($contents);
            $source .= $contents;
        }
        foreach ([
            'TradeEntryConfig',
            'OrderPlanBuilder\\',
            'ExecutionBox',
            'Provider\\',
            'Doctrine',
            'Messenger',
            'PrivateMainnetExecutionPort',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }
}
