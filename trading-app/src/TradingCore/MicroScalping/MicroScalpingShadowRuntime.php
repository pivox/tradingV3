<?php

declare(strict_types=1);

namespace App\TradingCore\MicroScalping;

use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\TradingCore\Config\EffectiveTradingConfigResolverInterface;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterSelector;
use App\TradingCore\Shadow\CanonicalShadowRuntime;
use App\TradingCore\Shadow\ShadowRuntimeIdentityPolicy;
use App\TradingCore\Shadow\ShadowRuntimeOutcome;
use App\TradingCore\Shadow\ShadowRuntimeRequest;
use Psr\Clock\ClockInterface;

final readonly class MicroScalpingShadowRuntime
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

    public function run(MicroScalpingShadowRequest $request): MicroScalpingShadowOutcome
    {
        return $this->toOutcome($this->runtime->run(
            $this->toShadowRequest($request),
            new ShadowRuntimeIdentityPolicy('micro_scalping_shadow', [
                [
                    'mode_id' => 'micro_scalping',
                    'mode_version' => '1.1.0',
                    'setup_id' => 'micro_scalping.momentum_ofi.long',
                    'setup_version' => '1.1.0',
                    'side' => 'long',
                ],
                [
                    'mode_id' => 'micro_scalping',
                    'mode_version' => '1.1.0',
                    'setup_id' => 'micro_scalping.momentum_ofi.short',
                    'setup_version' => '1.1.0',
                    'side' => 'short',
                ],
            ], requiresCanonicalOrderBook: true, requiresCanonicalMicrostructure: true),
        ));
    }

    private function toShadowRequest(MicroScalpingShadowRequest $request): ShadowRuntimeRequest
    {
        $plan = $request->orderPlanRequest;
        $planWithOrderBook = new CanonicalOrderPlanBuildRequest(
            $plan->policy,
            $plan->zoneRequest,
            $plan->zone,
            $plan->protectionRequest,
            $plan->protection,
            $plan->riskRequest,
            $plan->risk,
            $plan->netR,
            $plan->costs,
            $request->orderBook,
        );

        return new ShadowRuntimeRequest(
            $request->configRequest,
            $request->lineage,
            $request->indicatorsByTimeframe,
            $planWithOrderBook,
            $request->portfolioScope,
            $request->portfolioSnapshot,
            $request->decisionKey,
            $request->liveSpreadBps,
            $request->estimatedSlippageBps,
            $request->orderBook,
        );
    }

    private function toOutcome(ShadowRuntimeOutcome $outcome): MicroScalpingShadowOutcome
    {
        return new MicroScalpingShadowOutcome(
            $outcome->status,
            $outcome->reasonCode,
            $outcome->lineage,
            $outcome->orderPlan,
            $outcome->reservation,
            $outcome->evidence,
        );
    }
}
