<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanTime;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use Brick\Math\BigDecimal;
use Psr\Clock\ClockInterface;

final readonly class CanonicalPortfolioAdmissionEngine
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function admit(CanonicalPortfolioAdmissionRequest $request): CanonicalPortfolioReservationDecision
    {
        $policy = $request->policy;
        $plan = $request->plan;
        $snapshot = $request->snapshot;
        $scope = $request->scope;
        try {
            (new CanonicalOrderPlanValidator($this->clock))->validate($plan);
        } catch (CanonicalOrderPlanException $exception) {
            throw new CanonicalPortfolioException('canonical_portfolio_plan_invalid', [
                'plan_reason_code' => $exception->reasonCode,
            ], $exception);
        }
        if (
            $snapshot->scope != $scope
            || $scope->exchange !== $policy->exchange
            || $scope->environment !== $policy->environment
            || $scope->modeId !== $policy->modeId
            || $scope->quoteCurrency !== $policy->quoteCurrency
            || $plan->modeId !== $policy->modeId
            || $plan->modeVersion !== $policy->modeVersion
            || $plan->setupId !== $policy->setupId
            || $plan->setupVersion !== $policy->setupVersion
            || $plan->exchange !== $policy->exchange
            || $plan->environment !== $policy->environment
            || $plan->quoteCurrency !== $policy->quoteCurrency
            || $plan->side !== $policy->side
            || $plan->configHash !== $policy->configHash
            || $plan->equityQuote !== $snapshot->equityQuote
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_scope_mismatch');
        }

        $now = $this->clock->now();
        if ($snapshot->observedAt > $now) {
            throw new CanonicalPortfolioException('canonical_portfolio_state_future');
        }
        if (CanonicalOrderPlanTime::isOlderThan($snapshot->observedAt, $now, $plan->maximumInputAgeSeconds)) {
            throw new CanonicalPortfolioException('canonical_portfolio_state_stale');
        }
        [$dayStart, $dayEnd] = $this->policyDay($policy, $now);
        if ($snapshot->policyDayStart != $dayStart || $snapshot->policyDayEnd != $dayEnd) {
            throw new CanonicalPortfolioException('canonical_portfolio_state_day_mismatch');
        }
        if (\in_array($request->decisionKey, $snapshot->activeDecisionKeys, true)) {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_duplicate');
        }

        $equity = $this->decimal($snapshot->equityQuote);
        $dailyPercentageCap = $equity->multipliedBy($this->decimal($policy->dailyLossRate));
        $dailyAbsoluteCap = $this->decimal($policy->dailyLossAbsoluteQuote);
        $effectiveDailyCap = $dailyPercentageCap->isLessThan($dailyAbsoluteCap) ? $dailyPercentageCap : $dailyAbsoluteCap;
        $realizedLoss = $this->loss($snapshot->realizedNetPnlQuote);
        $unrealizedLoss = $policy->includeUnrealizedLoss
            ? $this->loss($snapshot->unrealizedNetPnlQuote)
            : BigDecimal::zero();
        $consumedDailyLoss = $realizedLoss
            ->plus($unrealizedLoss)
            ->plus($this->decimal($snapshot->reservedRiskQuote));
        $remainingDailyLoss = $effectiveDailyCap->minus($consumedDailyLoss);
        $candidateRisk = $this->decimal($plan->totalStopLoss);
        if ($remainingDailyLoss->isNegative() || $candidateRisk->isGreaterThan($remainingDailyLoss)) {
            throw new CanonicalPortfolioException('canonical_portfolio_daily_loss_exceeded');
        }

        $countedPending = $policy->includePendingEntries ? $snapshot->pendingEntries : 0;
        if (
            $snapshot->openPositions >= $policy->maxConcurrentPositions
            || $countedPending > $policy->maxConcurrentPositions - $snapshot->openPositions - 1
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_concurrency_exceeded');
        }
        $projectedConcurrent = $snapshot->openPositions + $countedPending + 1;

        $modeExposureCap = $equity->multipliedBy($this->decimal($policy->modeExposureRate));
        $projectedModeExposure = $this->decimal($snapshot->openNotionalQuote)
            ->plus($this->decimal($snapshot->pendingNotionalQuote))
            ->plus($this->decimal($plan->positionNotional));
        if ($projectedModeExposure->isGreaterThan($modeExposureCap)) {
            throw new CanonicalPortfolioException('canonical_portfolio_mode_exposure_exceeded');
        }

        $effectiveDailyCapFloat = CanonicalPortfolioDecimal::toFiniteFloat($effectiveDailyCap, 'canonical_portfolio_arithmetic_invalid');
        $consumedDailyLossFloat = CanonicalPortfolioDecimal::toFiniteFloat($consumedDailyLoss, 'canonical_portfolio_arithmetic_invalid');
        $remainingDailyLossFloat = CanonicalPortfolioDecimal::toFiniteFloat($remainingDailyLoss, 'canonical_portfolio_arithmetic_invalid');
        $candidateRiskFloat = CanonicalPortfolioDecimal::toFiniteFloat($candidateRisk, 'canonical_portfolio_arithmetic_invalid');
        $modeExposureCapFloat = CanonicalPortfolioDecimal::toFiniteFloat($modeExposureCap, 'canonical_portfolio_arithmetic_invalid');
        $projectedModeExposureFloat = CanonicalPortfolioDecimal::toFiniteFloat($projectedModeExposure, 'canonical_portfolio_arithmetic_invalid');
        $values = [
            'scope' => $scope->toArray(),
            'decision_key' => $request->decisionKey,
            'config_hash' => $policy->configHash,
            'plan_hash' => $plan->planHash,
            'portfolio_input_hash' => $snapshot->inputHash,
            'portfolio_source' => $snapshot->source,
            'portfolio_source_version' => $snapshot->sourceVersion,
            'expected_state_version' => $snapshot->stateVersion,
            'effective_daily_loss_cap_quote' => $effectiveDailyCapFloat,
            'consumed_daily_loss_quote' => $consumedDailyLossFloat,
            'remaining_daily_loss_before_candidate_quote' => $remainingDailyLossFloat,
            'reserved_risk_quote' => $candidateRiskFloat,
            'reserved_notional_quote' => $plan->positionNotional,
            'mode_exposure_cap_quote' => $modeExposureCapFloat,
            'projected_mode_exposure_quote' => $projectedModeExposureFloat,
            'projected_concurrent_positions' => $projectedConcurrent,
            'created_at' => $now->format('Y-m-d\TH:i:s.uP'),
        ];
        $identityValues = $values;
        unset($identityValues['created_at']);
        $reservationHash = 'sha256:' . hash('sha256', CanonicalPortfolioDecimal::encode(
            $identityValues,
            'canonical_portfolio_reservation_hash_invalid',
        ));

        return new CanonicalPortfolioReservationDecision(
            $scope,
            $request->decisionKey,
            $policy->configHash,
            $plan->planHash,
            $snapshot->inputHash,
            $snapshot->source,
            $snapshot->sourceVersion,
            $snapshot->stateVersion,
            $effectiveDailyCapFloat,
            $consumedDailyLossFloat,
            $remainingDailyLossFloat,
            $candidateRiskFloat,
            $plan->positionNotional,
            $modeExposureCapFloat,
            $projectedModeExposureFloat,
            $projectedConcurrent,
            $now,
            $reservationHash,
        );
    }

    private function decimal(float $value): BigDecimal
    {
        return CanonicalPortfolioDecimal::fromFloat($value, 'canonical_portfolio_arithmetic_invalid');
    }

    private function loss(float $netPnl): BigDecimal
    {
        $value = $this->decimal($netPnl);

        return $value->isNegative() ? $value->negated() : BigDecimal::zero();
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private function policyDay(CanonicalPortfolioPolicy $policy, \DateTimeImmutable $now): array
    {
        $timezone = new \DateTimeZone($policy->dayTimezone);
        $localNow = $now->setTimezone($timezone);
        $candidate = new \DateTimeImmutable(
            $localNow->format('Y-m-d') . 'T' . $policy->dayBoundaryLocal,
            $timezone,
        );
        $start = $localNow < $candidate ? $candidate->modify('-1 day') : $candidate;

        return [$start, $start->modify('+1 day')];
    }
}
