<?php

declare(strict_types=1);

namespace App\TradingCore\Shadow;

use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\Trading\Lineage\LineageContextException;
use App\TradingCore\Config\EffectiveTradingConfigResolverInterface;
use App\TradingCore\Config\Exception\TradingConfigException;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilderInterface;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanTime;
use App\TradingCore\Risk\Canonical\CanonicalRiskException;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterSelector;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionProof;
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
            $request->lineage->assertExecutableTradeContract();
        } catch (LineageContextException) {
            return $this->reject($request, $policy->reason('lineage_mismatch'));
        }
        $lineageSnapshot = $request->lineage->effectiveConfigSnapshot?->toArray();
        $snapshotHash = $lineageSnapshot['snapshot_hash'] ?? null;
        if (
            !\is_string($snapshotHash)
            || $request->lineage->effectiveConfigReference !== 'effective-config-snapshot:' . $snapshotHash
            || ($lineageSnapshot['request']['execution_capability'] ?? null) !== $capability->value
        ) {
            return $this->reject($request, $policy->reason('lineage_mismatch'));
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
            if ($policy->requiresCanonicalMicrostructure) {
                $microstructureGuard = $this->microstructureGuard($request, $rules->trace);
                if ($microstructureGuard !== null) {
                    return $this->reject($request, $policy->reason($microstructureGuard), ['rules' => $rules->trace]);
                }
            }

            $executionPolicy = $this->policyCompiler->compile($snapshot);
            if ($request->orderPlanRequest->policy->configHash !== $executionPolicy->configHash) {
                return $this->reject($request, $policy->reason('plan_config_mismatch'));
            }
            if ($policy->requiresCanonicalOrderBook) {
                $orderBookGuard = $this->orderBookGuard($request, $executionPolicy);
                if ($orderBookGuard !== null) {
                    return $this->reject($request, $policy->reason($orderBookGuard));
                }
            }
            $guardSuffix = $this->costGuard($request, $executionPolicy);
            if ($guardSuffix !== null) {
                return $this->reject($request, $policy->reason($guardSuffix));
            }
            if ($policy->requiresCanonicalOrderBook && !$this->orderBookCostsMatch($request)) {
                return $this->reject($request, $policy->reason('order_book_snapshot_mismatch'));
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
            $admissionProof = CanonicalPortfolioAdmissionProof::fromRequest($admission);
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
                    'admission_proof' => $admissionProof->toArray(),
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

    private function orderBookGuard(ShadowRuntimeRequest $request, CanonicalExecutionPolicy $policy): ?string
    {
        $book = $request->orderBook;
        if ($book === null) {
            return 'order_book_unavailable';
        }
        if ($request->orderPlanRequest->orderBook != $book) {
            return 'order_book_proof_mismatch';
        }
        $zone = $request->orderPlanRequest->zone;
        $costs = $request->orderPlanRequest->costs;
        if (
            $book->exchange !== $request->configRequest->exchange
            || $book->environment !== $request->configRequest->environment
            || $book->symbol !== $request->lineage->symbol
            || $book->marketType !== $request->lineage->marketType
            || $book->exchange !== $zone->exchange
            || $book->environment !== $zone->environment
            || $book->symbol !== $zone->symbol
            || $book->marketType !== $zone->marketType
            || $book->exchange !== $costs->exchange
            || $book->environment !== $costs->environment
            || $book->symbol !== $costs->symbol
            || $book->marketType !== $costs->marketType
        ) {
            return 'order_book_identity_mismatch';
        }
        if (
            $book->source !== $policy->costContract->entrySpreadSource
            || $book->source !== $policy->costContract->stopSpreadSource
            || $book->source !== $policy->costContract->targetSpreadSource
            || $book->source !== $costs->entrySpreadSource
            || $book->source !== $costs->stopSpreadSource
        ) {
            return 'order_book_source_mismatch';
        }
        foreach ($costs->targets as $target) {
            if ($book->source !== $target->spreadSource) {
                return 'order_book_source_mismatch';
            }
        }
        $now = $this->clock->now();
        if ($book->observedAt > $now) {
            return 'order_book_future';
        }
        if (CanonicalOrderPlanTime::isOlderThan($book->observedAt, $now, $policy->entryZone->maximumInputAgeSeconds)) {
            return 'order_book_stale';
        }
        if (
            $request->liveSpreadBps === null
            || abs($book->spreadBps - $request->liveSpreadBps) > 1.0e-9
        ) {
            return 'order_book_snapshot_mismatch';
        }
        $entryViolation = $book->entryViolation(
            $zone->side,
            $zone->entryPrice,
        );
        if ($entryViolation !== null) {
            return $entryViolation;
        }

        return null;
    }

    private function orderBookCostsMatch(ShadowRuntimeRequest $request): bool
    {
        $book = $request->orderBook;
        if ($book === null) {
            return false;
        }
        $costs = $request->orderPlanRequest->costs;
        if (
            !$this->spreadMatchesRate($book->spreadBps, $costs->entrySpreadRate)
            || !$this->spreadMatchesRate($book->spreadBps, $costs->stopSpreadRate)
        ) {
            return false;
        }
        foreach ($costs->targets as $target) {
            if (!$this->spreadMatchesRate($book->spreadBps, $target->spreadRate)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $trace */
    private function microstructureGuard(ShadowRuntimeRequest $request, array $trace): ?string
    {
        $microstructure = $trace['microstructure_input'] ?? null;
        if (!\is_array($microstructure)
            || ($microstructure['status'] ?? null) !== 'ready'
            || !\is_string($microstructure['input_hash'] ?? null)
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $microstructure['input_hash']) !== 1
        ) {
            return 'microstructure_proof_unavailable';
        }
        $spread = $this->conditionObservedValue($trace, 'spread_bps_lte');
        $book = $request->orderBook;
        $bookSourceRecordId = $microstructure['book_source_record_id'] ?? null;
        if (!$book instanceof CanonicalOrderBookSnapshot
            || !\is_string($bookSourceRecordId)
            || preg_match('/\A[a-f0-9]{64}\z/D', $bookSourceRecordId) !== 1
            || !hash_equals('sha256:' . $bookSourceRecordId, $book->inputHash)
        ) {
            return 'microstructure_book_record_mismatch';
        }
        if ($spread === null
            || $request->liveSpreadBps === null
            || !\is_finite($request->liveSpreadBps)
            || abs($spread - $request->liveSpreadBps) > 1.0e-9
            || !\is_float($microstructure['best_bid'] ?? null)
            || !\is_float($microstructure['best_ask'] ?? null)
            || !\is_float($microstructure['spread_bps'] ?? null)
            || abs($microstructure['best_bid'] - $book->bestBid) > 1.0e-9
            || abs($microstructure['best_ask'] - $book->bestAsk) > 1.0e-9
            || abs($microstructure['spread_bps'] - $book->spreadBps) > 1.0e-9
            || ($microstructure['book_observed_at'] ?? null) !== $book->observedAt->format('Y-m-d\TH:i:s.u\Z')
        ) {
            return 'microstructure_spread_mismatch';
        }

        return null;
    }

    /** @param array<array-key, mixed> $node */
    private function conditionObservedValue(array $node, string $conditionId): ?float
    {
        if (($node['condition_id'] ?? null) === $conditionId) {
            $value = $node['observed_value'] ?? null;

            return (\is_int($value) || \is_float($value)) && \is_finite((float) $value) ? (float) $value : null;
        }
        foreach ($node as $child) {
            if (!\is_array($child)) {
                continue;
            }
            $value = $this->conditionObservedValue($child, $conditionId);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function spreadMatchesRate(float $spreadBps, ?float $spreadRate): bool
    {
        return $spreadRate !== null
            && \is_finite($spreadRate)
            && abs($spreadBps - $spreadRate * 10_000.0) <= 1.0e-9;
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
