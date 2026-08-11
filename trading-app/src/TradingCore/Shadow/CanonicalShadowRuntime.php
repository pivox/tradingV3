<?php

declare(strict_types=1);

namespace App\TradingCore\Shadow;

use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\TradingCore\Config\EffectiveTradingConfigResolverInterface;
use App\TradingCore\Config\Exception\TradingConfigException;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilderInterface;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use App\TradingCore\Risk\Canonical\CanonicalRiskException;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterSelector;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioException;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy;
use Psr\Clock\ClockInterface;

final readonly class CanonicalShadowRuntime
{
    public function __construct(
        private EffectiveTradingConfigResolverInterface $configResolver,
        private CanonicalSetupRuleRuntime $ruleRuntime,
        private CanonicalExecutionPolicyCompiler $policyCompiler,
        private CanonicalOrderPlanBuilderInterface $orderPlanBuilder,
        private CanonicalPortfolioAdapterSelector $portfolioAdapters,
        private ClockInterface $clock,
    ) {
    }

    public function run(ShadowRuntimeRequest $request, ShadowRuntimeIdentityPolicy $policy): ShadowRuntimeOutcome
    {
        if (!$policy->accepts($request->configRequest)) {
            return $this->reject($request, $policy->reason('identity_unsupported'));
        }
        $capability = $request->configRequest->capability;
        if ($capability === null || !$capability->permitsShadow()) {
            return $this->reject($request, $policy->reason('capability_forbidden'));
        }

        try {
            $portfolioAdapter = $this->portfolioAdapters->select($capability);
            $snapshot = $this->configResolver->resolve($request->configRequest);
            if (!$this->lineageMatches($request, $snapshot->configHash, (string) $snapshot->conditionCatalogHash)) {
                return $this->reject($request, $policy->reason('lineage_mismatch'));
            }

            $rules = $this->ruleRuntime->evaluate(
                $request->lineage,
                $request->indicatorsByTimeframe,
                $this->clock->now(),
            );
            if (!$rules->passed) {
                return $this->reject($request, $policy->reason($rules->reasonCode), ['rules' => $rules->trace]);
            }

            $executionPolicy = $this->policyCompiler->compile($snapshot);
            if ($request->orderPlanRequest->policy->configHash !== $executionPolicy->configHash) {
                return $this->reject($request, $policy->reason('plan_config_mismatch'));
            }
            $guardSuffix = $this->costGuard($request, $executionPolicy);
            if ($guardSuffix !== null) {
                return $this->reject($request, $policy->reason($guardSuffix));
            }

            $plan = $this->orderPlanBuilder->build($request->orderPlanRequest);
            if ($plan->orderType !== 'limit') {
                return $this->reject($request, $policy->reason('non_limit_plan_forbidden'));
            }
            $admission = new CanonicalPortfolioAdmissionRequest(
                CanonicalPortfolioPolicy::fromSnapshot($snapshot),
                $plan,
                $request->portfolioScope,
                $request->portfolioSnapshot,
                $request->decisionKey,
            );
            $reservation = $portfolioAdapter->reserve($admission);

            return new ShadowRuntimeOutcome(
                'planned',
                $policy->reason('planned'),
                $request->lineage,
                $plan,
                $reservation,
                [
                    'config_hash' => $snapshot->configHash,
                    'plan_hash' => $plan->planHash,
                    'reservation_hash' => $reservation->stateHash,
                    'entry_expires_at' => $plan->expiresAt->format(DATE_ATOM),
                    'cancel_after_at' => $plan->cancelAfterAt?->format(DATE_ATOM),
                    'holding_expires_at' => $plan->holdingExpiresAt?->format(DATE_ATOM),
                    'rules' => $rules->trace,
                ],
            );
        } catch (CanonicalOrderPlanException|CanonicalPortfolioException|CanonicalRiskException $exception) {
            return $this->reject($request, $exception->reasonCode, ['domain_evidence' => $exception->evidence]);
        } catch (TradingConfigException $exception) {
            return $this->reject($request, $exception->getMessage());
        }
    }

    private function lineageMatches(ShadowRuntimeRequest $request, string $configHash, string $catalogHash): bool
    {
        $lineage = $request->lineage;
        $config = $request->configRequest;

        return $lineage->isModern()
            && $lineage->modeId === $config->modeId
            && $lineage->modeVersion === $config->modeVersion
            && $lineage->setupId === $config->setupId
            && $lineage->setupVersion === $config->setupVersion
            && strtolower((string) $lineage->side) === $config->side
            && $lineage->exchange === $config->exchange
            && $lineage->environment === $config->environment
            && $lineage->symbol === $request->orderPlanRequest->zone->symbol
            && $lineage->marketType === $request->orderPlanRequest->zone->marketType
            && $lineage->decisionKey === $request->decisionKey
            && $lineage->configHash === $configHash
            && $lineage->conditionCatalogHash === $catalogHash;
    }

    private function costGuard(ShadowRuntimeRequest $request, CanonicalExecutionPolicy $policy): ?string
    {
        $orderPolicy = $policy->orderPolicy;
        if ($orderPolicy === null) {
            return 'order_policy_unavailable';
        }
        if ($request->liveSpreadBps === null || !\is_finite($request->liveSpreadBps) || $request->liveSpreadBps < 0.0) {
            return 'live_spread_unavailable';
        }
        if ($request->estimatedSlippageBps === null || !\is_finite($request->estimatedSlippageBps) || $request->estimatedSlippageBps < 0.0) {
            return 'slippage_unavailable';
        }
        if ($request->liveSpreadBps > $orderPolicy->maximumSpreadBps) {
            return 'live_spread_exceeded';
        }
        if ($request->estimatedSlippageBps > $orderPolicy->maximumSlippageBps) {
            return 'slippage_exceeded';
        }
        $costs = $request->orderPlanRequest->costs;
        if (
            $costs->entryLiquidityRole !== $orderPolicy->liquidityRole
            || $costs->entryLiquidityRole !== $policy->costContract->entryLiquidityRole
            || $costs->stopLiquidityRole !== $policy->costContract->stopLiquidityRole
            || abs($request->liveSpreadBps - (float) $costs->entrySpreadRate * 10_000.0) > 1.0e-9
            || abs($request->estimatedSlippageBps - (float) $costs->entrySlippageRate * 10_000.0) > 1.0e-9
        ) {
            return 'live_cost_snapshot_mismatch';
        }

        return null;
    }

    /** @param array<string, mixed> $evidence */
    private function reject(ShadowRuntimeRequest $request, string $reasonCode, array $evidence = []): ShadowRuntimeOutcome
    {
        return new ShadowRuntimeOutcome(
            'no_trade',
            $reasonCode,
            $request->lineage,
            null,
            null,
            [
                'mode_id' => $request->lineage->modeId,
                'mode_version' => $request->lineage->modeVersion,
                'setup_id' => $request->lineage->setupId,
                'setup_version' => $request->lineage->setupVersion,
                'side' => $request->lineage->side,
                'config_hash' => $request->lineage->configHash,
                ...$evidence,
            ],
        );
    }
}
