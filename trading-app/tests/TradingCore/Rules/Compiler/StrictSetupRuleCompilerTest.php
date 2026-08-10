<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Rules\Compiler;

use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use App\TradingCore\Rules\Compiler\StrictSetupRuleCompiler;
use App\TradingCore\Setup\SetupContractLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrictSetupRuleCompiler::class)]
final class StrictSetupRuleCompilerTest extends TestCase
{
    public function testEveryRealSetupVersionCompilesAgainstExactCatalog(): void
    {
        $root = dirname(__DIR__, 4);
        $setupRoot = $root . '/config/trading/setup_contract';
        $catalog = (new ConditionCatalogLoader())->loadFile($root . '/config/trading/condition_catalog/1.0.0.yaml');
        $compiler = new StrictSetupRuleCompiler($catalog);
        $loader = new SetupContractLoader($setupRoot);
        $plans = [];

        foreach (glob($setupRoot . '/*/*.yaml') ?: [] as $path) {
            $setupId = basename(dirname($path));
            $setupVersion = basename($path, '.yaml');
            $contract = $loader->load($setupId, $setupVersion);
            $plan = $compiler->compile($contract);
            $plans[$setupId . '@' . $setupVersion] = $plan;
            self::assertSame($catalog->stableHash(), $plan->catalogHash, $path);
            self::assertSame($contract->stableHash(), $plan->setupHash, $path);
            self::assertSame($setupId, $plan->setupId, $path);
            self::assertSame($setupVersion, $plan->setupVersion, $path);
            self::assertNotSame([], $plan->sections, $path);
        }

        self::assertCount(10, $plans);
        self::assertSame([], $plans['day_trading.trend_continuation.long@1.1.0']->blockers);
        self::assertContains('blocked_condition:spread_bps_lte', $plans['micro_scalping.momentum_ofi.long@1.0.0']->blockers);
        self::assertContains('blocked_condition:order_flow_imbalance_gte', $plans['micro_scalping.momentum_ofi.long@1.0.0']->blockers);
        self::assertNotContains('blocked_condition:spread_bps_lte', $plans['scalping.pullback.long@1.0.0']->blockers);
    }
}
