<?php

declare(strict_types=1);

namespace App\TradingCore\DayTrading;

use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\TradingCore\Config\EffectiveTradingConfigResolverInterface;
use App\TradingCore\Config\Exception\TradingConfigException;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalHoldingBoundary;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\BacktestCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterInterface;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\FakeCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\PaperCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioException;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioPolicy;
use Psr\Clock\ClockInterface;

final readonly class DayTradingShadowRuntime
{
    public function __construct(
        private EffectiveTradingConfigResolverInterface $configResolver,
        private CanonicalSetupRuleRuntime $ruleRuntime,
        private CanonicalExecutionPolicyCompiler $policyCompiler,
        private CanonicalOrderPlanBuilder $orderPlanBuilder,
        private CanonicalPortfolioAdapterInterface $portfolioAdapter,
        private ClockInterface $clock,
    ) {
    }

    public function run(DayTradingShadowRequest $request): DayTradingShadowOutcome
    {
        $capability = $request->configRequest->capability;
        if ($capability === null || !$capability->permitsShadow()) {
            return $this->reject($request, 'day_trading_shadow_capability_forbidden');
        }
        if (!$this->adapterMatches($capability)) {
            return $this->reject($request, 'day_trading_shadow_adapter_mismatch');
        }

        try {
            $snapshot = $this->configResolver->resolve($request->configRequest);
            if (!$this->lineageMatches($request, $snapshot->configHash, (string) $snapshot->conditionCatalogHash)) {
                return $this->reject($request, 'day_trading_shadow_lineage_mismatch');
            }

            $rules = $this->ruleRuntime->evaluate(
                $request->lineage,
                $request->indicatorsByTimeframe,
                $this->clock->now(),
            );
            if (!$rules->passed) {
                return $this->reject($request, $rules->reasonCode, ['rules' => $rules->trace]);
            }

            $policy = $this->policyCompiler->compile($snapshot);
            if ($request->orderPlanRequest->policy->configHash !== $policy->configHash) {
                return $this->reject($request, 'day_trading_shadow_plan_config_mismatch');
            }
            $guard = $this->costGuard($request, $policy);
            if ($guard !== null) {
                return $this->reject($request, $guard);
            }

            $holdingExpiry = CanonicalHoldingBoundary::expiresAt(
                $this->clock->now(),
                $policy->holdingWindowSeconds,
                $policy->holdingHorizon,
            );
            $plan = $this->orderPlanBuilder->build($request->orderPlanRequest);
            if ($plan->orderType !== 'limit') {
                return $this->reject($request, 'day_trading_shadow_non_limit_plan_forbidden');
            }
            $admission = new CanonicalPortfolioAdmissionRequest(
                CanonicalPortfolioPolicy::fromSnapshot($snapshot),
                $plan,
                $request->portfolioScope,
                $request->portfolioSnapshot,
                $request->decisionKey,
            );
            $reservation = $this->portfolioAdapter->reserve($admission);

            return new DayTradingShadowOutcome(
                'planned',
                'day_trading_shadow_planned',
                $request->lineage,
                $plan,
                $reservation,
                [
                    'config_hash' => $snapshot->configHash,
                    'plan_hash' => $plan->planHash,
                    'reservation_hash' => $reservation->stateHash,
                    'holding_expires_at' => $holdingExpiry->format(DATE_ATOM),
                    'rules' => $rules->trace,
                ],
            );
        } catch (CanonicalOrderPlanException|CanonicalPortfolioException $exception) {
            return $this->reject($request, $exception->reasonCode, ['domain_evidence' => $exception->evidence]);
        } catch (TradingConfigException $exception) {
            return $this->reject($request, $exception->getMessage());
        }
    }

    private function adapterMatches(ShadowExecutionCapability $capability): bool
    {
        return match ($capability) {
            ShadowExecutionCapability::Fake => $this->portfolioAdapter instanceof FakeCanonicalPortfolioAdapter,
            ShadowExecutionCapability::Paper => $this->portfolioAdapter instanceof PaperCanonicalPortfolioAdapter,
            ShadowExecutionCapability::Backtest => $this->portfolioAdapter instanceof BacktestCanonicalPortfolioAdapter,
            ShadowExecutionCapability::PrivateMainnet => false,
        };
    }

    private function lineageMatches(DayTradingShadowRequest $request, string $configHash, string $catalogHash): bool
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
            && $lineage->configHash === $configHash
            && $lineage->conditionCatalogHash === $catalogHash;
    }

    private function costGuard(DayTradingShadowRequest $request, CanonicalExecutionPolicy $policy): ?string
    {
        $orderPolicy = $policy->orderPolicy;
        if ($orderPolicy === null) {
            return 'day_trading_order_policy_unavailable';
        }
        if ($request->liveSpreadBps === null || !\is_finite($request->liveSpreadBps) || $request->liveSpreadBps < 0.0) {
            return 'day_trading_live_spread_unavailable';
        }
        if ($request->estimatedSlippageBps === null || !\is_finite($request->estimatedSlippageBps) || $request->estimatedSlippageBps < 0.0) {
            return 'day_trading_slippage_unavailable';
        }
        if ($request->liveSpreadBps > $orderPolicy->maximumSpreadBps) {
            return 'day_trading_live_spread_exceeded';
        }
        if ($request->estimatedSlippageBps > $orderPolicy->maximumSlippageBps) {
            return 'day_trading_slippage_exceeded';
        }
        $costs = $request->orderPlanRequest->costs;
        if (
            $costs->entryLiquidityRole !== $orderPolicy->liquidityRole
            || $costs->entryLiquidityRole !== $policy->costContract->entryLiquidityRole
            || $costs->stopLiquidityRole !== $policy->costContract->stopLiquidityRole
            || $request->liveSpreadBps !== (float) $costs->entrySpreadRate * 10_000.0
            || $request->estimatedSlippageBps !== (float) $costs->entrySlippageRate * 10_000.0
        ) {
            return 'day_trading_live_cost_snapshot_mismatch';
        }

        return null;
    }

    /** @param array<string, mixed> $evidence */
    private function reject(DayTradingShadowRequest $request, string $reasonCode, array $evidence = []): DayTradingShadowOutcome
    {
        return new DayTradingShadowOutcome(
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
