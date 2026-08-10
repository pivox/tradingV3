<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class CanonicalPortfolioReservation
{
    /**
     * @param array<string, string> $appliedFillHashes
     * @param list<string>          $transitionInputHashes
     */
    private function __construct(
        public CanonicalPortfolioScope $scope,
        public string $decisionKey,
        public string $configHash,
        public string $planHash,
        public string $portfolioInputHash,
        public string $portfolioSource,
        public string $portfolioSourceVersion,
        public string $admissionHash,
        public float $reservedRiskQuote,
        public float $reservedNotionalQuote,
        public float $plannedQuantity,
        public float $quantityStep,
        public float $contractSize,
        public string $side,
        public float $stopPrice,
        public float $stopFeeRate,
        public float $stopSpreadRate,
        public float $stopSlippageRate,
        public float $fundingCostRate,
        public float $plannedFundingCostQuote,
        public float $filledQuantity,
        public float $protectedQuantity,
        public float $remainingQuantity,
        public float $venueRemainingQuantity,
        public float $filledEntryNotionalQuote,
        public float $accumulatedEntryFeeQuote,
        public float $accumulatedGrossStopLossQuote,
        public float $filledRiskQuote,
        public float $residualRiskQuote,
        public float $filledNotionalQuote,
        public float $residualNotionalQuote,
        public string $filledQuantityDecimal,
        public string $protectedQuantityDecimal,
        public string $remainingQuantityDecimal,
        public string $venueRemainingQuantityDecimal,
        public string $filledEntryNotionalDecimal,
        public string $accumulatedEntryFeeDecimal,
        public string $accumulatedGrossStopLossDecimal,
        public string $filledRiskDecimal,
        public string $residualRiskDecimal,
        public string $filledNotionalDecimal,
        public string $residualNotionalDecimal,
        public string $status,
        public string $requiredAction,
        public array $appliedFillHashes,
        public array $transitionInputHashes,
        public int $version,
        public ?string $previousStateHash,
        public \DateTimeImmutable $observedAt,
        public string $stateHash,
    ) {
    }

    public static function open(
        CanonicalPortfolioReservationDecision $decision,
        CanonicalOrderPlan $plan,
    ): self {
        if (
            $decision->configHash !== $plan->configHash
            || $decision->planHash !== $plan->planHash
            || $decision->scope->exchange !== $plan->exchange
            || $decision->scope->environment !== $plan->environment
            || $decision->scope->modeId !== $plan->modeId
            || $decision->scope->quoteCurrency !== $plan->quoteCurrency
            || $decision->reservedRiskQuote !== $plan->totalStopLoss
            || $decision->reservedNotionalQuote !== $plan->positionNotional
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_identity_mismatch');
        }
        $stopFeeRate = $plan->stopLiquidityRole === 'maker' ? $plan->makerFeeRate : $plan->takerFeeRate;
        $fundingCostRate = $plan->positionNotional > 0.0 ? $plan->fundingCost / $plan->positionNotional : 0.0;

        return self::create([
            'scope' => $decision->scope,
            'decisionKey' => $decision->decisionKey,
            'configHash' => $decision->configHash,
            'planHash' => $decision->planHash,
            'portfolioInputHash' => $decision->portfolioInputHash,
            'portfolioSource' => $decision->portfolioSource,
            'portfolioSourceVersion' => $decision->portfolioSourceVersion,
            'admissionHash' => $decision->reservationHash,
            'reservedRiskQuote' => $decision->reservedRiskQuote,
            'reservedNotionalQuote' => $decision->reservedNotionalQuote,
            'plannedQuantity' => $plan->quantity,
            'quantityStep' => $plan->quantityStep,
            'contractSize' => $plan->contractSize,
            'side' => $plan->side,
            'stopPrice' => $plan->stopPrice,
            'stopFeeRate' => $stopFeeRate,
            'stopSpreadRate' => $plan->stopSpreadRate,
            'stopSlippageRate' => $plan->stopSlippageRate,
            'fundingCostRate' => $fundingCostRate,
            'plannedFundingCostQuote' => $plan->fundingCost,
            'filledQuantity' => 0.0,
            'protectedQuantity' => 0.0,
            'remainingQuantity' => $plan->quantity,
            'venueRemainingQuantity' => $plan->quantity,
            'filledEntryNotionalQuote' => 0.0,
            'accumulatedEntryFeeQuote' => 0.0,
            'accumulatedGrossStopLossQuote' => 0.0,
            'filledRiskQuote' => 0.0,
            'residualRiskQuote' => $decision->reservedRiskQuote,
            'filledNotionalQuote' => 0.0,
            'residualNotionalQuote' => $decision->reservedNotionalQuote,
            'filledQuantityDecimal' => '0',
            'protectedQuantityDecimal' => '0',
            'remainingQuantityDecimal' => self::decimal($plan->quantity)->__toString(),
            'venueRemainingQuantityDecimal' => self::decimal($plan->quantity)->__toString(),
            'filledEntryNotionalDecimal' => '0',
            'accumulatedEntryFeeDecimal' => '0',
            'accumulatedGrossStopLossDecimal' => '0',
            'filledRiskDecimal' => '0',
            'residualRiskDecimal' => self::decimal($decision->reservedRiskQuote)->__toString(),
            'filledNotionalDecimal' => '0',
            'residualNotionalDecimal' => self::decimal($decision->reservedNotionalQuote)->__toString(),
            'status' => 'active',
            'requiredAction' => 'none',
            'appliedFillHashes' => [],
            'transitionInputHashes' => [$decision->portfolioInputHash],
            'version' => 1,
            'previousStateHash' => null,
            'observedAt' => $decision->createdAt,
        ]);
    }

    public function applyFill(CanonicalPortfolioFill $fill): self
    {
        $fillHash = $fill->eventHash();
        if (isset($this->appliedFillHashes[$fill->fillId])) {
            if (!hash_equals($this->appliedFillHashes[$fill->fillId], $fillHash)) {
                throw new CanonicalPortfolioException('canonical_portfolio_fill_id_conflict');
            }

            return $this;
        }
        if (
            $fill->scope != $this->scope
            || $fill->decisionKey !== $this->decisionKey
            || $fill->planHash !== $this->planHash
            || $fill->admissionHash !== $this->admissionHash
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_fill_identity_mismatch');
        }
        if (
            $this->status !== 'active'
            && !($this->status === 'compensation_required' && $this->venueRemainingQuantity > 0.0)
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_not_fillable');
        }
        if ($this->version === PHP_INT_MAX) {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_version_overflow');
        }
        if ($fill->observedAt < $this->observedAt) {
            throw new CanonicalPortfolioException('canonical_portfolio_fill_out_of_order');
        }

        $fillQuantity = self::decimal($fill->quantity);
        $fillCrossesStop =
            ($this->side === 'long' && $fill->price <= $this->stopPrice)
            || ($this->side === 'short' && $fill->price >= $this->stopPrice);
        $authorizedRemainingBefore = BigDecimal::of($this->remainingQuantityDecimal);
        $venueRemainingBefore = BigDecimal::of($this->venueRemainingQuantityDecimal);
        $step = self::decimal($this->quantityStep);
        if (
            !$fillQuantity->dividedBy($step, 0, RoundingMode::DOWN)->multipliedBy($step)->isEqualTo($fillQuantity)
            || !self::decimal($fill->remainingOrderQuantity)->dividedBy($step, 0, RoundingMode::DOWN)->multipliedBy($step)->isEqualTo(self::decimal($fill->remainingOrderQuantity))
            || !self::decimal($fill->protectedQuantityAfter)->dividedBy($step, 0, RoundingMode::DOWN)->multipliedBy($step)->isEqualTo(self::decimal($fill->protectedQuantityAfter))
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_fill_quantity_grid_invalid');
        }
        if ($fillQuantity->isGreaterThan($venueRemainingBefore)) {
            throw new CanonicalPortfolioException('canonical_portfolio_fill_quantity_exceeded');
        }
        $expectedVenueRemaining = $venueRemainingBefore->minus($fillQuantity);
        if (!$expectedVenueRemaining->isEqualTo(self::decimal($fill->remainingOrderQuantity))) {
            throw new CanonicalPortfolioException('canonical_portfolio_fill_remaining_mismatch');
        }
        $filledQuantity = BigDecimal::of($this->filledQuantityDecimal)->plus($fillQuantity);
        $protectedQuantity = self::decimal($fill->protectedQuantityAfter);
        if (
            $protectedQuantity->isLessThan(BigDecimal::of($this->protectedQuantityDecimal))
            || $protectedQuantity->isGreaterThan($filledQuantity)
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_fill_protection_invalid');
        }

        $fillNotional = $fillQuantity
            ->multipliedBy(self::decimal($fill->price))
            ->multipliedBy(self::decimal($this->contractSize));
        $filledEntryNotional = BigDecimal::of($this->filledEntryNotionalDecimal)->plus($fillNotional);
        $accumulatedEntryFee = BigDecimal::of($this->accumulatedEntryFeeDecimal)->plus(self::decimal($fill->entryFeeQuote));
        $filledContractQuantity = $filledQuantity->multipliedBy(self::decimal($this->contractSize));
        $stopPrice = self::decimal($this->stopPrice);
        $stopNotional = $stopPrice->multipliedBy($filledContractQuantity);
        $fillStopNotional = $stopPrice
            ->multipliedBy($fillQuantity)
            ->multipliedBy(self::decimal($this->contractSize));
        $grossStopLoss = BigDecimal::of($this->accumulatedGrossStopLossDecimal)
            ->plus($fillNotional->minus($fillStopNotional)->abs());
        $filledFundingCost = self::decimal($this->plannedFundingCostQuote)
            ->multipliedBy($filledEntryNotional)
            ->dividedBy(self::decimal($this->reservedNotionalQuote), 24, RoundingMode::UP);
        $filledRisk = $grossStopLoss
            ->plus($accumulatedEntryFee)
            ->plus($stopNotional->multipliedBy(self::decimal($this->stopFeeRate)))
            ->plus($stopNotional->multipliedBy(self::decimal($this->stopSpreadRate)))
            ->plus($stopNotional->multipliedBy(self::decimal($this->stopSlippageRate)))
            ->plus($filledFundingCost);

        $reservedRisk = self::decimal($this->reservedRiskQuote);
        $plannedQuantity = self::decimal($this->plannedQuantity);
        $reservedNotional = self::decimal($this->reservedNotionalQuote);
        $authorizedAfterFill = $authorizedRemainingBefore->minus($fillQuantity);
        if ($authorizedAfterFill->isNegative()) {
            $authorizedAfterFill = BigDecimal::zero();
        }
        $allowedRemaining = $authorizedAfterFill->isLessThan($expectedVenueRemaining)
            ? $authorizedAfterFill
            : $expectedVenueRemaining;
        $requiredAction = $expectedVenueRemaining->isZero()
            ? 'none'
            : ($allowedRemaining->isLessThan($expectedVenueRemaining) ? ($allowedRemaining->isZero() ? 'cancel_residual' : 'reduce_residual') : 'keep_residual');
        $status = $expectedVenueRemaining->isZero() ? 'filled' : 'active';

        if ($this->status === 'compensation_required') {
            $allowedRemaining = BigDecimal::zero();
            $requiredAction = $this->requiredAction;
            $status = 'compensation_required';
        } elseif ($fillCrossesStop) {
            $allowedRemaining = BigDecimal::zero();
            $requiredAction = 'compensate_stop_crossed_fill';
            $status = 'compensation_required';
        } elseif ($protectedQuantity->isLessThan($filledQuantity)) {
            $allowedRemaining = BigDecimal::zero();
            $requiredAction = 'compensate_unprotected_fill';
            $status = 'compensation_required';
        } elseif ($filledRisk->isGreaterThan($reservedRisk)) {
            $allowedRemaining = BigDecimal::zero();
            $requiredAction = 'compensate_over_budget_fill';
            $status = 'compensation_required';
        } else {
            $availableResidualRisk = $reservedRisk->minus($filledRisk);
            $maximumRemaining = $availableResidualRisk
                ->multipliedBy($plannedQuantity)
                ->dividedBy($reservedRisk, 24, RoundingMode::DOWN);
            $maximumRemaining = $maximumRemaining
                ->dividedBy($step, 0, RoundingMode::DOWN)
                ->multipliedBy($step);
            if ($maximumRemaining->isLessThan($allowedRemaining)) {
                $allowedRemaining = $maximumRemaining;
            }
            if ($allowedRemaining->isLessThan($expectedVenueRemaining)) {
                $requiredAction = $allowedRemaining->isZero() ? 'cancel_residual' : 'reduce_residual';
            }
        }

        $residualRisk = $reservedRisk
            ->multipliedBy($allowedRemaining)
            ->dividedBy($plannedQuantity, 24, RoundingMode::UP);
        $residualNotional = $reservedNotional
            ->multipliedBy($allowedRemaining)
            ->dividedBy($plannedQuantity, 24, RoundingMode::UP);
        if (
            $status === 'active'
            && $filledRisk->plus($residualRisk)->isGreaterThan($reservedRisk)
            && !$allowedRemaining->isZero()
        ) {
            $allowedRemaining = $allowedRemaining->minus($step);
            $residualRisk = $reservedRisk
                ->multipliedBy($allowedRemaining)
                ->dividedBy($plannedQuantity, 24, RoundingMode::UP);
            $residualNotional = $reservedNotional
                ->multipliedBy($allowedRemaining)
                ->dividedBy($plannedQuantity, 24, RoundingMode::UP);
            $requiredAction = $allowedRemaining->isZero() ? 'cancel_residual' : 'reduce_residual';
        }
        if ($status === 'active' && $filledRisk->plus($residualRisk)->isGreaterThan($reservedRisk)) {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_arithmetic_invalid');
        }
        $fillHashes = $this->appliedFillHashes;
        $fillHashes[$fill->fillId] = $fillHash;
        ksort($fillHashes);
        $transitionInputHashes = [...$this->transitionInputHashes, $fill->inputHash];

        return self::create(array_replace($this->values(), [
            'filledQuantity' => self::float($filledQuantity),
            'protectedQuantity' => self::float($protectedQuantity),
            'remainingQuantity' => self::float($allowedRemaining),
            'venueRemainingQuantity' => self::float($expectedVenueRemaining),
            'filledEntryNotionalQuote' => self::float($filledEntryNotional),
            'accumulatedEntryFeeQuote' => self::float($accumulatedEntryFee),
            'accumulatedGrossStopLossQuote' => self::float($grossStopLoss),
            'filledRiskQuote' => self::float($filledRisk),
            'residualRiskQuote' => self::float($residualRisk),
            'filledNotionalQuote' => self::float($filledEntryNotional),
            'residualNotionalQuote' => self::float($residualNotional),
            'filledQuantityDecimal' => $filledQuantity->__toString(),
            'protectedQuantityDecimal' => $protectedQuantity->__toString(),
            'remainingQuantityDecimal' => $allowedRemaining->__toString(),
            'venueRemainingQuantityDecimal' => $expectedVenueRemaining->__toString(),
            'filledEntryNotionalDecimal' => $filledEntryNotional->__toString(),
            'accumulatedEntryFeeDecimal' => $accumulatedEntryFee->__toString(),
            'accumulatedGrossStopLossDecimal' => $grossStopLoss->__toString(),
            'filledRiskDecimal' => $filledRisk->__toString(),
            'residualRiskDecimal' => $residualRisk->__toString(),
            'filledNotionalDecimal' => $filledEntryNotional->__toString(),
            'residualNotionalDecimal' => $residualNotional->__toString(),
            'status' => $status,
            'requiredAction' => $requiredAction,
            'appliedFillHashes' => $fillHashes,
            'transitionInputHashes' => $transitionInputHashes,
            'version' => $this->version + 1,
            'previousStateHash' => $this->stateHash,
            'observedAt' => $fill->observedAt,
        ]));
    }

    public function cancelResidual(\DateTimeImmutable $observedAt, string $inputHash): self
    {
        self::terminalInput($observedAt, $inputHash, $this->observedAt);
        if ($this->venueRemainingQuantity === 0.0) {
            return $this;
        }
        if ($this->version === PHP_INT_MAX) {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_version_overflow');
        }

        return self::create(array_replace($this->values(), [
            'remainingQuantity' => 0.0,
            'venueRemainingQuantity' => 0.0,
            'residualRiskQuote' => 0.0,
            'residualNotionalQuote' => 0.0,
            'remainingQuantityDecimal' => '0',
            'venueRemainingQuantityDecimal' => '0',
            'residualRiskDecimal' => '0',
            'residualNotionalDecimal' => '0',
            'status' => $this->status === 'compensation_required'
                ? 'compensation_required'
                : ($this->filledQuantity > 0.0 ? 'partially_filled' : 'cancelled'),
            'requiredAction' => $this->status === 'compensation_required' ? $this->requiredAction : 'none',
            'transitionInputHashes' => [...$this->transitionInputHashes, $inputHash],
            'version' => $this->version + 1,
            'previousStateHash' => $this->stateHash,
            'observedAt' => $observedAt,
        ]));
    }

    public function acknowledgeResidualReduction(
        float $venueRemainingQuantity,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): self {
        self::terminalInput($observedAt, $inputHash, $this->observedAt);
        if ($this->status !== 'active') {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_not_reducible');
        }
        $venueRemaining = self::decimal($venueRemainingQuantity);
        $step = self::decimal($this->quantityStep);
        if (
            $venueRemaining->isZero()
            || !$venueRemaining->dividedBy($step, 0, RoundingMode::DOWN)->multipliedBy($step)->isEqualTo($venueRemaining)
            || !$venueRemaining->isEqualTo(BigDecimal::of($this->remainingQuantityDecimal))
            || $venueRemaining->isGreaterThan(BigDecimal::of($this->venueRemainingQuantityDecimal))
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_reduction_mismatch');
        }
        if ($venueRemaining->isEqualTo(BigDecimal::of($this->venueRemainingQuantityDecimal))) {
            return $this;
        }
        if ($this->version === PHP_INT_MAX) {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_version_overflow');
        }

        return self::create(array_replace($this->values(), [
            'venueRemainingQuantity' => self::float($venueRemaining),
            'venueRemainingQuantityDecimal' => $venueRemaining->__toString(),
            'requiredAction' => 'keep_residual',
            'transitionInputHashes' => [...$this->transitionInputHashes, $inputHash],
            'version' => $this->version + 1,
            'previousStateHash' => $this->stateHash,
            'observedAt' => $observedAt,
        ]));
    }

    public function close(\DateTimeImmutable $observedAt, string $inputHash): self
    {
        self::terminalInput($observedAt, $inputHash, $this->observedAt);
        if ($this->status === 'closed') {
            return $this;
        }
        if ($this->version === PHP_INT_MAX) {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_version_overflow');
        }

        return self::create(array_replace($this->values(), [
            'remainingQuantity' => 0.0,
            'venueRemainingQuantity' => 0.0,
            'filledRiskQuote' => 0.0,
            'residualRiskQuote' => 0.0,
            'filledNotionalQuote' => 0.0,
            'residualNotionalQuote' => 0.0,
            'remainingQuantityDecimal' => '0',
            'venueRemainingQuantityDecimal' => '0',
            'filledRiskDecimal' => '0',
            'residualRiskDecimal' => '0',
            'filledNotionalDecimal' => '0',
            'residualNotionalDecimal' => '0',
            'status' => 'closed',
            'requiredAction' => 'none',
            'transitionInputHashes' => [...$this->transitionInputHashes, $inputHash],
            'version' => $this->version + 1,
            'previousStateHash' => $this->stateHash,
            'observedAt' => $observedAt,
        ]));
    }

    /** @param array<string, mixed> $values */
    private static function create(array $values): self
    {
        unset($values['stateHash']);
        $hashValues = $values;
        $scope = $hashValues['scope'] ?? null;
        $observedAt = $hashValues['observedAt'] ?? null;
        if (!$scope instanceof CanonicalPortfolioScope || !$observedAt instanceof \DateTimeImmutable) {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_state_invalid');
        }
        $hashValues['scope'] = $scope->toArray();
        $hashValues['observedAt'] = $observedAt->format('Y-m-d\TH:i:s.uP');
        $values['stateHash'] = 'sha256:' . hash('sha256', CanonicalPortfolioDecimal::encode(
            $hashValues,
            'canonical_portfolio_reservation_hash_invalid',
        ));

        return new self(...$values);
    }

    /** @return array<string, mixed> */
    private function values(): array
    {
        return get_object_vars($this);
    }

    private static function decimal(float $value): BigDecimal
    {
        return CanonicalPortfolioDecimal::fromFloat($value, 'canonical_portfolio_reservation_arithmetic_invalid');
    }

    private static function float(BigDecimal $value): float
    {
        return CanonicalPortfolioDecimal::toFiniteFloat(
            $value,
            'canonical_portfolio_reservation_arithmetic_invalid',
        );
    }

    private static function terminalInput(
        \DateTimeImmutable $observedAt,
        string $inputHash,
        \DateTimeImmutable $previousObservedAt,
    ): void {
        if ($observedAt < $previousObservedAt || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $inputHash) !== 1) {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_terminal_input_invalid');
        }
    }
}
