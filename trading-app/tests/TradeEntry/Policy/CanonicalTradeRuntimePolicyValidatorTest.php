<?php

declare(strict_types=1);

namespace App\Tests\TradeEntry\Policy;

use App\Config\TradeEntryConfigResolver;
use App\Logging\Dto\LifecycleContextBuilder;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Policy\CanonicalTradeRuntimePolicyValidator;
use App\TradeEntry\Service\TradeEntryPreparationService;
use App\TradeEntry\Types\Side;
use App\TradeEntry\Workflow\BuildOrderPlan;
use App\TradeEntry\Workflow\BuildPreOrder;
use App\Trading\Lineage\CanonicalRuntimePolicyException;
use App\Trading\Lineage\CanonicalTradeEntryConfigFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalTradeRuntimePolicyValidator::class)]
#[CoversClass(CanonicalRuntimePolicyException::class)]
#[CoversClass(TradeEntryPreparationService::class)]
final class CanonicalTradeRuntimePolicyValidatorTest extends TestCase
{
    public function testReportsAllPending304PoliciesInStableOrder(): void
    {
        $config = CanonicalTradeEntryConfigFactory::fromLineage($this->identity());

        self::assertSame([
            ['code' => 'canonical_risk_pct_pending_304', 'path' => 'runtime.trade_entry.risk_pct'],
            ['code' => 'canonical_daily_loss_policy_pending_304', 'path' => 'mode.risk.daily_loss_cap'],
            ['code' => 'canonical_max_concurrent_positions_pending_304', 'path' => 'mode.risk.max_concurrent_positions'],
            ['code' => 'canonical_mode_exposure_cap_pending_304', 'path' => 'mode.risk.mode_exposure_cap'],
            ['code' => 'canonical_minimum_net_r_pending_304', 'path' => 'setup.ast.execution.minimum_net_r'],
        ], CanonicalTradeRuntimePolicyValidator::blockers($config));

        self::assertSame([
            ['code' => 'canonical_risk_pct_pending_304', 'path' => 'runtime.trade_entry.risk_pct'],
            ['code' => 'canonical_daily_loss_policy_pending_304', 'path' => 'mode.risk.daily_loss_cap'],
            ['code' => 'canonical_end_of_zone_fallback_pending_304', 'path' => 'runtime.trade_entry.fallback_end_of_zone'],
            ['code' => 'canonical_max_concurrent_positions_pending_304', 'path' => 'mode.risk.max_concurrent_positions'],
            ['code' => 'canonical_mode_exposure_cap_pending_304', 'path' => 'mode.risk.mode_exposure_cap'],
            ['code' => 'canonical_minimum_net_r_pending_304', 'path' => 'setup.ast.execution.minimum_net_r'],
        ], CanonicalTradeRuntimePolicyValidator::blockers($config, true));
    }

    public function testModernPreparationRejectsBeforePreflightOrPlanner(): void
    {
        /** @var BuildPreOrder $preflight */
        $preflight = (new \ReflectionClass(BuildPreOrder::class))->newInstanceWithoutConstructor();
        /** @var BuildOrderPlan $planner */
        $planner = (new \ReflectionClass(BuildOrderPlan::class))->newInstanceWithoutConstructor();
        /** @var TradeEntryConfigResolver $resolver */
        $resolver = (new \ReflectionClass(TradeEntryConfigResolver::class))->newInstanceWithoutConstructor();
        $service = new TradeEntryPreparationService($preflight, $planner, $resolver);
        $identity = $this->identity()->withDecision('018f47a2-4f42-7e1b-8d3a-4dc9571bb11b', 'decision-key');
        $request = new TradeEntryRequest(
            'BTCUSDT', Side::Long, '1m', initialMarginUsdt: 50.0,
            exchangeContext: \App\Provider\Context\ExchangeContext::fromValues('fake', 'perpetual'),
            lineageContext: $identity,
        );

        try {
            $service->prepare($request, 'decision-key', 'scalping', new LifecycleContextBuilder('BTCUSDT'));
            self::fail('Modern preparation reached provider-backed preflight.');
        } catch (CanonicalRuntimePolicyException $exception) {
            self::assertSame('canonical_risk_pct_pending_304', $exception->getMessage());
            self::assertSame(CanonicalTradeRuntimePolicyValidator::blockers(
                CanonicalTradeEntryConfigFactory::fromLineage($identity)
            ), $exception->blockers);
        }
    }

    public function testModernFallbackRewriteIsBlockedButLegacyRewriteGuardIsNoOp(): void
    {
        CanonicalTradeRuntimePolicyValidator::assertNoEndOfZoneFallbackRewrite(false, ['order_type' => 'market']);
        self::addToAssertionCount(1);

        $this->expectException(CanonicalRuntimePolicyException::class);
        $this->expectExceptionMessage('canonical_end_of_zone_fallback_pending_304');
        CanonicalTradeRuntimePolicyValidator::assertNoEndOfZoneFallbackRewrite(true, ['order_type' => 'market']);
    }

    private function identity(): \App\Trading\Lineage\LineageContext
    {
        return CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config());
    }
}
