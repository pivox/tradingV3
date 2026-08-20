<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\MicroScalping;

use App\TradingCore\MicroScalping\MicroScalpingShadowRuntime;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class MicroScalpingShadowDependencyTest extends TestCase
{
    public function testFacadeDelegatesOnlyToCanonicalBoundaries(): void
    {
        $constructor = (new \ReflectionClass(MicroScalpingShadowRuntime::class))->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame([
            'App\TradingCore\Config\EffectiveTradingConfigResolverInterface',
            'App\MtfValidator\Policy\CanonicalSetupRuleRuntime',
            'App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler',
            'App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder',
            'App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterSelector',
            'Psr\Clock\ClockInterface',
        ], array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters(),
        ));

        $root = dirname(__DIR__, 3) . '/src/TradingCore/MicroScalping';
        $source = '';
        foreach (glob($root . '/*.php') ?: [] as $file) {
            $contents = file_get_contents($file);
            self::assertIsString($contents);
            $source .= $contents;
        }
        self::assertStringContainsString('CanonicalShadowRuntime', $source);
        self::assertStringContainsString('requiresCanonicalMicrostructure: true', $source);
        foreach (['TradeEntryConfig', 'ExecutionBox', 'Provider\\', 'Doctrine', 'Messenger', 'PrivateMainnetExecutionPort'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }
}
