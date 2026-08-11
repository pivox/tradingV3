<?php

declare(strict_types=1);

namespace App\TradingCore\DayTrading;

use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\TradingCore\Config\EffectiveTradingConfigResolverInterface;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterSelector;
use App\TradingCore\Shadow\CanonicalShadowRuntime;
use App\TradingCore\Shadow\ShadowRuntimeIdentityPolicy;
use App\TradingCore\Shadow\ShadowRuntimeOutcome;
use App\TradingCore\Shadow\ShadowRuntimeRequest;
use Psr\Clock\ClockInterface;

final readonly class DayTradingShadowRuntime
{
    private CanonicalShadowRuntime $runtime;

    public function __construct(
        EffectiveTradingConfigResolverInterface $configResolver,
        CanonicalSetupRuleRuntime $ruleRuntime,
        CanonicalExecutionPolicyCompiler $policyCompiler,
        CanonicalOrderPlanBuilder $orderPlanBuilder,
        CanonicalPortfolioAdapterSelector $portfolioAdapters,
        ClockInterface $clock,
    ) {
        $this->runtime = new CanonicalShadowRuntime(
            $configResolver,
            $ruleRuntime,
            $policyCompiler,
            $orderPlanBuilder,
            $portfolioAdapters,
            $clock,
        );
    }

    public function run(DayTradingShadowRequest $request): DayTradingShadowOutcome
    {
        return $this->toDayTradingOutcome($this->runtime->run(
            $this->toShadowRequest($request),
            new ShadowRuntimeIdentityPolicy('day_trading_shadow', [[
                'mode_id' => 'day_trading',
                'mode_version' => '1.1.0',
                'setup_id' => 'day_trading.trend_continuation.long',
                'setup_version' => '1.1.0',
                'side' => 'long',
            ]]),
        ));
    }

    private function toShadowRequest(DayTradingShadowRequest $request): ShadowRuntimeRequest
    {
        return new ShadowRuntimeRequest(
            $request->configRequest,
            $request->lineage,
            $request->indicatorsByTimeframe,
            $request->orderPlanRequest,
            $request->portfolioScope,
            $request->portfolioSnapshot,
            $request->decisionKey,
            $request->liveSpreadBps,
            $request->estimatedSlippageBps,
        );
    }

    private function toDayTradingOutcome(ShadowRuntimeOutcome $outcome): DayTradingShadowOutcome
    {
        return new DayTradingShadowOutcome(
            $outcome->status,
            $this->legacyReasonCode($outcome->reasonCode),
            $outcome->lineage,
            $outcome->orderPlan,
            $outcome->reservation,
            $outcome->evidence,
        );
    }

    private function legacyReasonCode(string $reasonCode): string
    {
        return match ($reasonCode) {
            'day_trading_shadow_order_policy_unavailable' => 'day_trading_order_policy_unavailable',
            'day_trading_shadow_live_spread_unavailable' => 'day_trading_live_spread_unavailable',
            'day_trading_shadow_slippage_unavailable' => 'day_trading_slippage_unavailable',
            'day_trading_shadow_live_spread_exceeded' => 'day_trading_live_spread_exceeded',
            'day_trading_shadow_slippage_exceeded' => 'day_trading_slippage_exceeded',
            'day_trading_shadow_live_cost_snapshot_mismatch' => 'day_trading_live_cost_snapshot_mismatch',
            default => $reasonCode,
        };
    }
}
