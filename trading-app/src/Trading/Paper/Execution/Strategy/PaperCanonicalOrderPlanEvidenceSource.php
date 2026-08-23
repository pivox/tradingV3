<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjection;
use App\TradingCore\Execution\Hyperliquid\HyperliquidPriceStep;
use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZoneEngine;
use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZoneRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionCostSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalMarketSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalNetREngine;
use App\TradingCore\OrderPlan\Canonical\CanonicalNetRRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use App\TradingCore\OrderPlan\Canonical\CanonicalPriceObservation;
use App\TradingCore\OrderPlan\Canonical\CanonicalProtectionEngine;
use App\TradingCore\OrderPlan\Canonical\CanonicalProtectionDecision;
use App\TradingCore\OrderPlan\Canonical\CanonicalProtectionRequest;
use App\TradingCore\Risk\Canonical\CanonicalCostSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalRiskCalculationRequest;
use App\TradingCore\Risk\Canonical\CanonicalRiskEngine;
use App\TradingCore\Risk\Canonical\CanonicalRiskException;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use Brick\Math\BigDecimal;
use Psr\Clock\ClockInterface;

final readonly class PaperCanonicalOrderPlanEvidenceSource
{
    /** @var list<string> */
    private const EXPECTED_NO_PLAN = [
        'canonical_entry_zone_candidate_outside',
        'canonical_risk_available_balance_exhausted',
        'canonical_risk_quantity_below_minimum',
        'canonical_risk_notional_below_minimum',
        'canonical_minimum_net_r_not_met',
        'canonical_hyperliquid_price_precision_invalid',
    ];

    public function __construct(
        private ClockInterface $clock,
        private CanonicalProtectionEngine $protection = new CanonicalProtectionEngine(),
        private CanonicalRiskEngine $risk = new CanonicalRiskEngine(),
        private CanonicalNetREngine $netR = new CanonicalNetREngine(),
    ) {
    }

    public function build(
        CanonicalExecutionPolicy $policy,
        CanonicalIndicatorProjection $projection,
        PaperCanonicalInstrumentEvidence $instrument,
        CanonicalOrderBookSnapshot $book,
        CanonicalExecutionCostSnapshot $costs,
        CanonicalPortfolioSnapshot $portfolio,
    ): ?CanonicalOrderPlanBuildRequest {
        try {
            $this->assertIdentity($policy, $projection, $instrument, $book, $costs, $portfolio);
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            $projectionData = $projection->toArray();
            $snapshots = $projectionData['snapshots_by_timeframe'];
            $observedAt = $this->timestamp($projectionData['evaluated_at'] ?? null);
            if ($observedAt > $now) {
                throw new \LogicException('paper_canonical_order_plan_projection_future');
            }

            $anchor = $this->observation(
                $policy,
                $book->symbol,
                $snapshots,
                $policy->entryZone->anchorTimeframe,
                $policy->entryZone->anchorSource,
                $observedAt,
                (string) $projectionData['result_hash'],
            );
            $zoneAtr = $this->observation(
                $policy,
                $book->symbol,
                $snapshots,
                $policy->entryZone->atrTimeframe,
                'atr',
                $observedAt,
                (string) $projectionData['result_hash'],
            );
            $candidate = $policy->riskPolicy->side === 'long' ? $book->bestBid : $book->bestAsk;
            $zoneRequest = new CanonicalEntryZoneRequest(
                $policy,
                $book->symbol,
                $anchor,
                $zoneAtr,
                new CanonicalMarketSnapshot(
                    $book->exchange,
                    $book->environment,
                    $book->symbol,
                    $book->marketType,
                    $book->source,
                    $candidate,
                    $book->observedAt,
                    $book->inputHash,
                ),
                $instrument->tick,
            );
            $zone = CanonicalEntryZoneEngine::calculateAt($zoneRequest, $now);

            $stopInput = $this->observation(
                $policy,
                $book->symbol,
                $snapshots,
                $policy->stop->timeframe,
                $policy->stop->kind === 'atr' ? 'atr' : (string) $policy->stop->pivotId,
                $observedAt,
                (string) $projectionData['result_hash'],
            );
            $protectionRequest = new CanonicalProtectionRequest(
                $policy,
                $zone,
                $policy->stop->kind === 'atr' ? $stopInput : null,
                $policy->stop->kind === 'pivot' ? $stopInput : null,
            );
            $protection = $this->protection->calculate($protectionRequest);
            $this->assertHyperliquidPricePrecision($instrument, $protection);
            $fundingIntervals = intdiv(
                $policy->holdingWindowSeconds - 1,
                $policy->costContract->fundingIntervalSeconds,
            ) + 1;
            $riskCosts = new CanonicalCostSnapshot(
                $costs->entryLiquidityRole,
                $costs->stopLiquidityRole,
                $costs->entrySpreadRate,
                $costs->stopSpreadRate,
                $costs->entrySlippageRate,
                $costs->stopSlippageRate,
                $costs->fundingRate,
                $fundingIntervals,
            );
            $riskInstrument = $instrument->instrument;
            $riskRequest = new CanonicalRiskCalculationRequest(
                $policy->riskPolicy,
                $book->symbol,
                $book->marketType,
                $riskInstrument->quoteCurrency,
                $policy->riskPolicy->side,
                $portfolio->equityQuote,
                $portfolio->equityQuote,
                $protection->entryPrice,
                $protection->stopPrice,
                $riskInstrument->contractSize,
                $riskInstrument->quantityStep,
                $riskInstrument->minQuantity,
                $riskInstrument->maxQuantity,
                $riskInstrument->marketMaxQuantity,
                $riskInstrument->exchangeLeverageCap,
                $riskInstrument->symbolLeverageCap,
                $riskCosts,
                $riskInstrument,
            );
            $risk = $this->risk->calculate($riskRequest);
            $netR = $this->netR->calculate(new CanonicalNetRRequest($policy, $protection, $risk, $costs));

            return new CanonicalOrderPlanBuildRequest(
                $policy,
                $zoneRequest,
                $zone,
                $protectionRequest,
                $protection,
                $riskRequest,
                $risk,
                $netR,
                $costs,
                $book,
            );
        } catch (CanonicalOrderPlanException|CanonicalRiskException $exception) {
            if (in_array($exception->reasonCode, self::EXPECTED_NO_PLAN, true)) {
                return null;
            }

            throw $exception;
        }
    }

    private function assertHyperliquidPricePrecision(
        PaperCanonicalInstrumentEvidence $instrument,
        CanonicalProtectionDecision $protection,
    ): void {
        if ($instrument->instrument->exchange !== 'hyperliquid') {
            return;
        }
        $minimumTick = BigDecimal::of((string) $instrument->tick->tickSize);
        $prices = [$protection->entryPrice, $protection->stopPrice];
        foreach ($protection->targets as $target) {
            $prices[] = $target->price;
        }
        foreach ($prices as $price) {
            $decimal = BigDecimal::of((string) $price);
            if (!HyperliquidPriceStep::isValid($decimal, $minimumTick)) {
                throw new CanonicalOrderPlanException('canonical_hyperliquid_price_precision_invalid');
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $snapshots
     */
    private function observation(
        CanonicalExecutionPolicy $policy,
        string $symbol,
        array $snapshots,
        string $timeframe,
        string $source,
        \DateTimeImmutable $observedAt,
        string $projectionHash,
    ): CanonicalPriceObservation {
        $snapshot = $snapshots[$timeframe] ?? null;
        $value = is_array($snapshot) ? ($snapshot[$source] ?? null) : null;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value <= 0) {
            throw new \LogicException('paper_canonical_order_plan_indicator_missing');
        }
        $inputHash = 'sha256:' . hash('sha256', CanonicalJson::encode([
            'projection_hash' => $projectionHash,
            'timeframe' => $timeframe,
            'source' => $source,
            'value' => (float) $value,
        ]));

        return new CanonicalPriceObservation(
            $policy->riskPolicy->exchange,
            $policy->riskPolicy->environment,
            $symbol,
            'perpetual',
            $source,
            $timeframe,
            (float) $value,
            $observedAt,
            $inputHash,
        );
    }

    private function assertIdentity(
        CanonicalExecutionPolicy $policy,
        CanonicalIndicatorProjection $projection,
        PaperCanonicalInstrumentEvidence $instrument,
        CanonicalOrderBookSnapshot $book,
        CanonicalExecutionCostSnapshot $costs,
        CanonicalPortfolioSnapshot $portfolio,
    ): void {
        $risk = $policy->riskPolicy;
        $projectionData = $projection->toArray();
        if (($projectionData['symbol'] ?? null) !== $book->symbol
            || $book->exchange !== $risk->exchange
            || $book->environment !== $risk->environment
            || $book->marketType !== 'perpetual'
            || $book->source !== $policy->costContract->entrySpreadSource
            || $instrument->instrument->exchange !== $book->exchange
            || $instrument->instrument->environment !== $book->environment
            || $instrument->instrument->symbol !== $book->symbol
            || $instrument->instrument->marketType !== $book->marketType
            || $costs->exchange !== $book->exchange
            || $costs->environment !== $book->environment
            || $costs->symbol !== $book->symbol
            || $costs->marketType !== $book->marketType
            || !hash_equals($costs->configHash, $policy->configHash)
            || $portfolio->scope->exchange !== $book->exchange
            || $portfolio->scope->environment !== $book->environment
            || $portfolio->scope->modeId !== $risk->modeId
            || $portfolio->scope->quoteCurrency !== $instrument->instrument->quoteCurrency
        ) {
            throw new \LogicException('paper_canonical_order_plan_identity_mismatch');
        }
    }

    private function timestamp(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new \LogicException('paper_canonical_order_plan_projection_invalid');
        }
        $timestamp = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\\TH:i:s.u\\Z',
            $value,
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$timestamp instanceof \DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new \LogicException('paper_canonical_order_plan_projection_invalid');
        }

        return $timestamp;
    }
}
