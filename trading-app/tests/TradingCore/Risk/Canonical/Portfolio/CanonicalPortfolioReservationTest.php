<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Risk\Canonical\Portfolio;

use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioException;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioFill;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservation;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CanonicalPortfolioReservation::class)]
final class CanonicalPortfolioReservationTest extends TestCase
{
    public function testOpensReservationBoundToAdmissionAndPlan(): void
    {
        [$reservation, $plan] = $this->reservation();

        self::assertSame('active', $reservation->status);
        self::assertSame($plan->quantity, $reservation->remainingQuantity);
        self::assertSame($plan->quantity, $reservation->venueRemainingQuantity);
        self::assertSame(0.0, $reservation->filledQuantity);
        self::assertSame(0.0, $reservation->filledRiskQuote);
        self::assertSame($plan->totalStopLoss, $reservation->residualRiskQuote);
        self::assertSame($plan->positionNotional, $reservation->residualNotionalQuote);
        self::assertSame(1, $reservation->version);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $reservation->stateHash);
    }

    public function testPartialFillKeepsFilledAndResidualRiskSeparateAndProtected(): void
    {
        [$reservation, $plan] = $this->reservation();
        $remaining = round($plan->quantity - 1.0, 3);
        $fill = $this->fill($reservation,
            quantity: 1.0,
            price: $plan->entryPrice,
            entryFeeQuote: $plan->entryFee / $plan->quantity,
            protectedQuantityAfter: 1.0,
            remainingOrderQuantity: $remaining,
        );

        $next = $reservation->applyFill($fill);

        self::assertSame('active', $next->status);
        self::assertSame('keep_residual', $next->requiredAction);
        self::assertSame(1.0, $next->filledQuantity);
        self::assertSame(1.0, $next->protectedQuantity);
        self::assertEqualsWithDelta($remaining, $next->remainingQuantity, 1e-12);
        self::assertGreaterThan(0.0, $next->filledRiskQuote);
        self::assertGreaterThan(0.0, $next->residualRiskQuote);
        self::assertLessThanOrEqual($next->reservedRiskQuote, $next->filledRiskQuote + $next->residualRiskQuote);
        self::assertSame(2, $next->version);
        self::assertSame([
            'sha256:' . str_repeat('8', 64),
            'sha256:' . str_repeat('9', 64),
        ], $next->transitionInputHashes);
    }

    public function testAdverseFillReducesOrCancelsResidualToStayInsideReservation(): void
    {
        [$reservation, $plan] = $this->reservation();
        $fill = $this->fill($reservation,
            quantity: 2.0,
            price: $plan->entryPrice + 0.2,
            entryFeeQuote: 0.2,
            protectedQuantityAfter: 2.0,
            remainingOrderQuantity: round($plan->quantity - 2.0, 3),
        );

        $next = $reservation->applyFill($fill);

        self::assertContains($next->requiredAction, ['reduce_residual', 'cancel_residual']);
        self::assertLessThanOrEqual($next->reservedRiskQuote, $next->filledRiskQuote + $next->residualRiskQuote);
        self::assertLessThan($fill->remainingOrderQuantity, $next->remainingQuantity);
    }

    public function testFillArrivingBeforeResidualReductionAcknowledgementIsStillAccounted(): void
    {
        [$reservation, $plan] = $this->reservation();
        $venueRemainingAfterFirstFill = round($plan->quantity - 2.0, 3);
        $reduced = $reservation->applyFill($this->fill(
            $reservation,
            quantity: 2.0,
            price: $plan->entryPrice + 0.2,
            entryFeeQuote: 0.2,
            protectedQuantityAfter: 2.0,
            remainingOrderQuantity: $venueRemainingAfterFirstFill,
        ));
        self::assertLessThan($venueRemainingAfterFirstFill, $reduced->remainingQuantity);

        $lateFill = $reduced->applyFill($this->fill(
            $reduced,
            fillId: 'fill-2',
            quantity: 0.2,
            price: $plan->entryPrice + 0.2,
            entryFeeQuote: 0.05,
            protectedQuantityAfter: 2.2,
            remainingOrderQuantity: round($venueRemainingAfterFirstFill - 0.2, 3),
        ));

        self::assertSame(2.2, $lateFill->filledQuantity);
        self::assertGreaterThan($reduced->filledRiskQuote, $lateFill->filledRiskQuote);
        self::assertContains($lateFill->requiredAction, ['reduce_residual', 'cancel_residual', 'compensate_over_budget_fill']);
    }

    public function testResidualReductionAcknowledgementUpdatesVenueOutstandingSeparately(): void
    {
        [$reservation, $plan] = $this->reservation();
        $reduced = $reservation->applyFill($this->fill(
            $reservation,
            quantity: 1.0,
            price: $plan->entryPrice + 0.4,
            entryFeeQuote: 0.1,
            protectedQuantityAfter: 1.0,
            remainingOrderQuantity: round($plan->quantity - 1.0, 3),
        ));
        self::assertSame('reduce_residual', $reduced->requiredAction);
        self::assertGreaterThan($reduced->remainingQuantity, $reduced->venueRemainingQuantity);

        $acknowledged = $reduced->acknowledgeResidualReduction(
            $reduced->remainingQuantity,
            new \DateTimeImmutable('2026-08-10T12:00:02+00:00'),
            'sha256:' . str_repeat('a', 64),
        );

        self::assertSame($acknowledged->remainingQuantity, $acknowledged->venueRemainingQuantity);
        self::assertSame('keep_residual', $acknowledged->requiredAction);
        self::assertSame($reduced->stateHash, $acknowledged->previousStateHash);
    }

    public function testUnprotectedFilledQuantityRequiresCompensationAndAccountsForInFlightFill(): void
    {
        [$reservation, $plan] = $this->reservation();
        $next = $reservation->applyFill($this->fill($reservation,
            quantity: 1.0,
            price: $plan->entryPrice,
            entryFeeQuote: 0.05,
            protectedQuantityAfter: 0.5,
            remainingOrderQuantity: round($plan->quantity - 1.0, 3),
        ));

        self::assertSame('compensation_required', $next->status);
        self::assertSame('compensate_unprotected_fill', $next->requiredAction);
        self::assertSame(0.0, $next->remainingQuantity);
        self::assertSame(0.0, $next->residualRiskQuote);

        $inFlight = $next->applyFill($this->fill($next,
            fillId: 'fill-2',
            quantity: 0.1,
            price: $plan->entryPrice,
            entryFeeQuote: 0.01,
            protectedQuantityAfter: 1.1,
            remainingOrderQuantity: round($plan->quantity - 1.1, 3),
        ));
        self::assertSame(1.1, $inFlight->filledQuantity);
        self::assertSame('compensation_required', $inFlight->status);
        self::assertSame('compensate_unprotected_fill', $inFlight->requiredAction);

        $cancelAcknowledged = $inFlight->cancelResidual(
            new \DateTimeImmutable('2026-08-10T12:00:02+00:00'),
            'sha256:' . str_repeat('a', 64),
        );
        self::assertSame('compensation_required', $cancelAcknowledged->status);
        self::assertSame('compensate_unprotected_fill', $cancelAcknowledged->requiredAction);

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_reservation_not_fillable');
        $cancelAcknowledged->applyFill($this->fill($cancelAcknowledged,
            fillId: 'fill-3',
            quantity: 0.1,
            price: $plan->entryPrice,
            entryFeeQuote: 0.01,
            protectedQuantityAfter: 1.2,
            remainingOrderQuantity: 0.0,
        ));
    }

    public function testDuplicateFillIsIdempotentButConflictingFillIdRejects(): void
    {
        [$reservation, $plan] = $this->reservation();
        $fill = $this->fill($reservation,
            quantity: 1.0,
            price: $plan->entryPrice,
            entryFeeQuote: 0.05,
            protectedQuantityAfter: 1.0,
            remainingOrderQuantity: round($plan->quantity - 1.0, 3),
        );
        $next = $reservation->applyFill($fill);

        self::assertSame($next, $next->applyFill($fill));

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_fill_id_conflict');
        $next->applyFill($this->fill($next,
            quantity: 0.5,
            price: $plan->entryPrice,
            entryFeeQuote: 0.02,
            protectedQuantityAfter: 1.5,
            remainingOrderQuantity: round($plan->quantity - 1.5, 3),
        ));
    }

    public function testRejectsFillFromAnotherAccountScope(): void
    {
        [$reservation, $plan] = $this->reservation();
        $foreign = new CanonicalPortfolioFill(
            new CanonicalPortfolioScope('paper-mainnet', 'fake', 'test', 'account-2', 'day_trading', 'USDT'),
            $reservation->decisionKey,
            $reservation->planHash,
            $reservation->admissionHash,
            'fill-foreign',
            1.0,
            $plan->entryPrice,
            0.05,
            1.0,
            round($plan->quantity - 1.0, 3),
            new \DateTimeImmutable('2026-08-10T12:00:01+00:00'),
            'sha256:' . str_repeat('9', 64),
        );

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_fill_identity_mismatch');
        $reservation->applyFill($foreign);
    }

    public function testRejectsFillQuantityOutsideInstrumentGrid(): void
    {
        [$reservation, $plan] = $this->reservation();

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_fill_quantity_grid_invalid');
        $reservation->applyFill($this->fill(
            $reservation,
            quantity: 0.0005,
            price: $plan->entryPrice,
            entryFeeQuote: 0.0,
            protectedQuantityAfter: 0.0005,
            remainingOrderQuantity: round($plan->quantity - 0.0005, 4),
        ));
    }

    public function testAccountsForFillWhosePriceCrossesStopAndRequiresCompensation(): void
    {
        [$reservation, $plan] = $this->reservation();

        $next = $reservation->applyFill($this->fill(
            $reservation,
            quantity: 1.0,
            price: $plan->stopPrice,
            entryFeeQuote: 0.0,
            protectedQuantityAfter: 1.0,
            remainingOrderQuantity: round($plan->quantity - 1.0, 3),
        ));

        self::assertSame(1.0, $next->filledQuantity);
        self::assertGreaterThan(0.0, $next->filledRiskQuote);
        self::assertSame('compensation_required', $next->status);
        self::assertSame('compensate_stop_crossed_fill', $next->requiredAction);
        self::assertSame(0.0, $next->remainingQuantity);
    }

    public function testStopCrossedFillCannotReduceRiskAlreadyChargedByEarlierFill(): void
    {
        [$reservation, $plan] = $this->reservation();
        $first = $reservation->applyFill($this->fill(
            $reservation,
            quantity: 1.0,
            price: $plan->stopPrice + 1.0,
            entryFeeQuote: 0.05,
            protectedQuantityAfter: 1.0,
            remainingOrderQuantity: round($plan->quantity - 1.0, 3),
        ));
        $crossed = $first->applyFill($this->fill(
            $first,
            fillId: 'fill-2',
            quantity: 1.0,
            price: $plan->stopPrice - 1.0,
            entryFeeQuote: 0.05,
            protectedQuantityAfter: 2.0,
            remainingOrderQuantity: round($plan->quantity - 2.0, 3),
        ));

        self::assertGreaterThan($first->filledRiskQuote, $crossed->filledRiskQuote);
        self::assertEqualsWithDelta(2.0 * $plan->contractSize, $crossed->accumulatedGrossStopLossQuote, 1e-12);
        self::assertSame('compensation_required', $crossed->status);
        self::assertSame('compensate_stop_crossed_fill', $crossed->requiredAction);
    }

    public function testCancelAndCloseReleaseRiskIdempotently(): void
    {
        [$reservation, $plan] = $this->reservation();
        $partiallyFilled = $reservation->applyFill($this->fill($reservation,
            quantity: 1.0,
            price: $plan->entryPrice,
            entryFeeQuote: 0.05,
            protectedQuantityAfter: 1.0,
            remainingOrderQuantity: round($plan->quantity - 1.0, 3),
        ));

        $cancelled = $partiallyFilled->cancelResidual(
            new \DateTimeImmutable('2026-08-10T12:00:02+00:00'),
            'sha256:' . str_repeat('a', 64),
        );
        self::assertSame('partially_filled', $cancelled->status);
        self::assertSame(0.0, $cancelled->remainingQuantity);
        self::assertSame(0.0, $cancelled->venueRemainingQuantity);
        self::assertSame(0.0, $cancelled->residualRiskQuote);
        self::assertContains('sha256:' . str_repeat('a', 64), $cancelled->transitionInputHashes);
        self::assertSame($cancelled, $cancelled->cancelResidual(
            new \DateTimeImmutable('2026-08-10T12:00:03+00:00'),
            'sha256:' . str_repeat('a', 64),
        ));

        $closed = $cancelled->close(
            new \DateTimeImmutable('2026-08-10T12:00:04+00:00'),
            'sha256:' . str_repeat('b', 64),
        );
        self::assertSame('closed', $closed->status);
        self::assertSame(0.0, $closed->filledRiskQuote);
        self::assertContains('sha256:' . str_repeat('b', 64), $closed->transitionInputHashes);
        self::assertSame($closed, $closed->close(
            new \DateTimeImmutable('2026-08-10T12:00:05+00:00'),
            'sha256:' . str_repeat('b', 64),
        ));
    }

    /** @return array{CanonicalPortfolioReservation, \App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan} */
    private function reservation(): array
    {
        $policy = CanonicalPortfolioFixture::policy();
        $plan = CanonicalPortfolioFixture::plan();
        $scope = new CanonicalPortfolioScope('paper-mainnet', 'fake', 'test', 'account-1', 'day_trading', 'USDT');
        $snapshot = new CanonicalPortfolioSnapshot(
            $scope,
            'golden_fixture',
            '1.0.0',
            new \DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-11T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-10T11:59:50+00:00'),
            1000.0,
            0.0,
            0.0,
            0,
            0,
            0.0,
            0.0,
            0.0,
            [],
            1,
            'sha256:' . str_repeat('8', 64),
        );
        $decision = (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))
            ->admit(new CanonicalPortfolioAdmissionRequest($policy, $plan, $scope, $snapshot, 'decision-1'));

        return [CanonicalPortfolioReservation::open($decision, $plan), $plan];
    }

    private function fill(
        CanonicalPortfolioReservation $reservation,
        string $fillId = 'fill-1',
        float $quantity = 1.0,
        float $price = 100.1,
        float $entryFeeQuote = 0.05,
        float $protectedQuantityAfter = 1.0,
        float $remainingOrderQuantity = 1.0,
    ): CanonicalPortfolioFill {
        return new CanonicalPortfolioFill(
            $reservation->scope,
            $reservation->decisionKey,
            $reservation->planHash,
            $reservation->admissionHash,
            $fillId,
            $quantity,
            $price,
            $entryFeeQuote,
            $protectedQuantityAfter,
            $remainingOrderQuantity,
            new \DateTimeImmutable('2026-08-10T12:00:01+00:00'),
            'sha256:' . str_repeat('9', 64),
        );
    }
}
