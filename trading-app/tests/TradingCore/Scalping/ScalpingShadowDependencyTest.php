<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Scalping;

use App\TradingCore\Scalping\ScalpingShadowRuntime;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ScalpingShadowDependencyTest extends TestCase
{
    public function testFacadeOwnsOnlyCanonicalBoundariesAndDelegatesAllOrchestration(): void
    {
        $constructor = (new \ReflectionClass(ScalpingShadowRuntime::class))->getConstructor();
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

        $source = file_get_contents(dirname(__DIR__, 3) . '/src/TradingCore/Scalping/ScalpingShadowRuntime.php');
        self::assertIsString($source);
        self::assertStringContainsString('CanonicalShadowRuntime', $source);
        self::assertStringContainsString("'scalping_shadow'", $source);
        foreach (['->evaluate(', '->compile(', '->build(', '->reserve(', 'CanonicalPortfolioAdmissionRequest'] as $duplicated) {
            self::assertStringNotContainsString($duplicated, $source);
        }
    }

    public function testCanonicalCoreKeepsMarketPlansFailClosedBehindFacadePrefix(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/TradingCore/Shadow/CanonicalShadowRuntime.php');
        self::assertIsString($source);
        self::assertStringContainsString("if (\$plan->orderType !== 'limit')", $source);
        self::assertStringContainsString("\$policy->reason('non_limit_plan_forbidden')", $source);
    }
}
