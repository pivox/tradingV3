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
        $evidence = $outcome->evidence;
        unset($evidence['admission_proof']);

        return new DayTradingShadowOutcome(
            $outcome->status,
            $this->legacyReasonCode($outcome->reasonCode),
            $outcome->lineage,
            $outcome->orderPlan,
            $outcome->reservation,
            $evidence,
        );
    }

    private function legacyReasonCode(string $reasonCode): string
    {
        return match ($reasonCode) {
            'day_trading_shadow_canonical_identity_required' => 'canonical_identity_required',
            'day_trading_shadow_canonical_condition_catalog_mismatch' => 'canonical_condition_catalog_mismatch',
            'day_trading_shadow_critical_timeframe_missing' => 'critical_timeframe_missing',
            'day_trading_shadow_critical_timeframe_stale' => 'critical_timeframe_stale',
            'day_trading_shadow_compiled_plan_blocked' => 'compiled_plan_blocked',
            'day_trading_shadow_setup_section_failed' => 'setup_section_failed',
            'day_trading_shadow_setup_filter_failed' => 'setup_filter_failed',
            'day_trading_shadow_no_trade_rule_matched' => 'no_trade_rule_matched',
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
