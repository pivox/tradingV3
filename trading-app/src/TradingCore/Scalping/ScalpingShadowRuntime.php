<?php

declare(strict_types=1);

namespace App\TradingCore\Scalping;

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

final readonly class ScalpingShadowRuntime
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

    public function run(ScalpingShadowRequest $request): ScalpingShadowOutcome
    {
        return $this->toScalpingOutcome($this->runtime->run(
            $this->toShadowRequest($request),
            new ShadowRuntimeIdentityPolicy('scalping_shadow', [
                [
                    'mode_id' => 'scalping',
                    'mode_version' => '1.1.0',
                    'setup_id' => 'scalping.trend_continuation.long',
                    'setup_version' => '1.1.0',
                    'side' => 'long',
                ],
                [
                    'mode_id' => 'scalping',
                    'mode_version' => '1.1.0',
                    'setup_id' => 'scalping.pullback.long',
                    'setup_version' => '1.1.0',
                    'side' => 'long',
                ],
                [
                    'mode_id' => 'scalping',
                    'mode_version' => '1.1.0',
                    'setup_id' => 'scalping.trend_momentum.short',
                    'setup_version' => '1.1.0',
                    'side' => 'short',
                ],
            ], requiresCanonicalOrderBook: true),
        ));
    }

    private function toShadowRequest(ScalpingShadowRequest $request): ShadowRuntimeRequest
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
            $request->orderBook,
        );
    }

    private function toScalpingOutcome(ShadowRuntimeOutcome $outcome): ScalpingShadowOutcome
    {
        return new ScalpingShadowOutcome(
            $outcome->status,
            $outcome->reasonCode,
            $outcome->lineage,
            $outcome->orderPlan,
            $outcome->reservation,
            $outcome->evidence,
        );
    }
}
