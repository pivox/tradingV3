<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\BacktestCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterSelector;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\FakeCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\PaperCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
use App\TradingCore\Risk\Canonical\Portfolio\InMemoryCanonicalPortfolioReservationStore;
use App\TradingCore\Shadow\CanonicalShadowRuntime;
use App\TradingCore\Shadow\ShadowRuntimeIdentityPolicy;
use App\TradingCore\Shadow\ShadowRuntimeOutcome;
use App\TradingCore\Shadow\ShadowRuntimeRequest;

final readonly class PaperCanonicalStrategyRuntime implements PaperCanonicalStrategyRuntimeInterface
{
    private CanonicalShadowRuntime $runtime;

    public function __construct(
        EffectiveTradingConfigResolver $configResolver,
        CanonicalSetupRuleRuntime $ruleRuntime,
        CanonicalExecutionPolicyCompiler $policyCompiler,
        PaperReplayClock $clock,
    ) {
        $admission = new CanonicalPortfolioAdmissionEngine($clock);
        $this->runtime = new CanonicalShadowRuntime(
            $configResolver,
            $ruleRuntime,
            $policyCompiler,
            new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)),
            new CanonicalPortfolioAdapterSelector(
                new FakeCanonicalPortfolioAdapter(
                    $admission,
                    new InMemoryCanonicalPortfolioReservationStore(),
                ),
                new PaperCanonicalPortfolioAdapter(
                    $admission,
                    new PaperCanonicalPortfolioReservationStore(),
                ),
                new BacktestCanonicalPortfolioAdapter(
                    $admission,
                    new InMemoryCanonicalPortfolioReservationStore(),
                ),
            ),
            $clock,
        );
    }

    public function run(
        ShadowRuntimeRequest $request,
        ShadowRuntimeIdentityPolicy $policy,
    ): ShadowRuntimeOutcome {
        return $this->runtime->run($request, $policy);
    }
}
