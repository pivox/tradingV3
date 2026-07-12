<?php

declare(strict_types=1);

namespace App\Tests\Provider\Hyperliquid;

use App\Provider\Hyperliquid\Dto\HyperliquidMarginSafetyEvidence;
use App\Provider\Hyperliquid\Dto\HyperliquidMarginTierEvidence;
use App\Provider\Hyperliquid\Dto\HyperliquidIsolatedLiquidationResult;
use App\Provider\Hyperliquid\HyperliquidIsolatedLiquidationSolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidIsolatedLiquidationSolver::class)]
final class HyperliquidIsolatedLiquidationSolverTest extends TestCase
{
    public function testShortEntryNotionalBelowThresholdUsesLiquidationTierAboveThreshold(): void
    {
        $result = $this->solver()->solve($this->evidence(), '99', '100', 10, 'short');

        self::assertSame(1, $result->tierIndex);
        self::assertSame('10000', $result->tierLowerBound);
        self::assertSame('103.545454545454545454545454545454545454', $result->liquidationPrice);
    }

    public function testExactTierBoundaryBelongsOnlyToUpperTier(): void
    {
        $result = $this->solver()->solve($this->evidence(), '118.75', '100', 5, 'long');

        self::assertSame(1, $result->tierIndex);
        self::assertSame('100', $result->liquidationPrice);
    }

    public function testSupportsRepeatingThreeXRateWithoutFloatConversion(): void
    {
        $evidence = $this->evidence([
            new HyperliquidMarginTierEvidence('0', 3, '0.166666666666666666666666666666666667', '0'),
        ], universeMaxLeverage: 3);

        $result = $this->solver()->solve($evidence, '100', '1', 3, 'long');

        self::assertSame('80.000000000000000000000000000000000033', $result->liquidationPrice);
        self::assertSame(80.00000000000101, $this->solver()->toConservativeFloat($result, 'long'));
    }

    public function testRejectsWhenNoTierCandidateMatchesItsInterval(): void
    {
        $evidence = $this->evidence([
            new HyperliquidMarginTierEvidence('0', 10, '0.05', '0'),
            new HyperliquidMarginTierEvidence('10000', 5, '0.9', '0'),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->solver()->solve($evidence, '99', '100', 10, 'short');
    }

    public function testRejectsWhenMultipleTierCandidatesMatch(): void
    {
        $evidence = $this->evidence([
            new HyperliquidMarginTierEvidence('0', 10, '0.05', '0'),
            new HyperliquidMarginTierEvidence('10000', 5, '0.1', '0'),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->solver()->solve($evidence, '117', '100', 5, 'long');
    }

    public function testOpeningLeverageIsCheckedAgainstEntryNotionalTierSeparately(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->solver()->solve($this->evidence(), '101', '100', 10, 'long');
    }

    public function testRejectsRequestedOrTierLeverageAboveOfficialMaximum(): void
    {
        $evidence = $this->evidence([
            new HyperliquidMarginTierEvidence('0', 51, '0.009803921568627451', '0'),
        ], universeMaxLeverage: 51);

        $this->expectException(\InvalidArgumentException::class);
        $this->solver()->solve($evidence, '100', '1', 51, 'long');
    }

    public function testHugeLongMovesExactlyOneUlpHigher(): void
    {
        $result = new HyperliquidIsolatedLiquidationResult(
            '10000000000000000.000000000001', 0, '0', 5, '0.1', '0',
        );

        self::assertSame('10000000000000002', sprintf('%.17g', $this->solver()->toConservativeFloat($result, 'long')));
    }

    public function testHugeShortMovesExactlyOneUlpLower(): void
    {
        $result = new HyperliquidIsolatedLiquidationResult(
            '9999999999999999.999999999999', 0, '0', 5, '0.1', '0',
        );

        self::assertSame('9999999999999998', sprintf('%.17g', $this->solver()->toConservativeFloat($result, 'short')));
    }

    public function testPointOneShortMovesExactlyOneUlpLower(): void
    {
        $result = new HyperliquidIsolatedLiquidationResult('0.1', 0, '0', 5, '0.1', '0');

        self::assertSame('0.099999999999999992', sprintf('%.17g', $this->solver()->toConservativeFloat($result, 'short')));
    }

    public function testPointThreeLongMovesExactlyOneUlpHigher(): void
    {
        $result = new HyperliquidIsolatedLiquidationResult('0.3', 0, '0', 5, '0.1', '0');

        self::assertSame('0.30000000000000004', sprintf('%.17g', $this->solver()->toConservativeFloat($result, 'long')));
    }

    public function testPointOneLongAlsoMovesExactlyOneUlpTowardEntry(): void
    {
        $result = new HyperliquidIsolatedLiquidationResult('0.1', 0, '0', 5, '0.1', '0');

        self::assertSame('0.10000000000000002', sprintf('%.17g', $this->solver()->toConservativeFloat($result, 'long')));
    }

    public function testReviewerShortOnePointEightMovesBelowActualBinaryValue(): void
    {
        $result = new HyperliquidIsolatedLiquidationResult('1.8', 0, '0', 5, '0.1', '0');

        self::assertSame('1.7999999999999998', sprintf('%.17g', $this->solver()->toConservativeFloat($result, 'short')));
    }

    public function testReviewerLongOnePointSixFiveMovesAboveActualBinaryValue(): void
    {
        $result = new HyperliquidIsolatedLiquidationResult('1.65', 0, '0', 5, '0.1', '0');

        self::assertSame('1.6500000000000001', sprintf('%.17g', $this->solver()->toConservativeFloat($result, 'long')));
    }

    public function testLongUpperFiniteBoundaryRejectsInfinityAfterOneUlp(): void
    {
        $result = new HyperliquidIsolatedLiquidationResult(
            sprintf('%.0f', PHP_FLOAT_MAX), 0, '0', 5, '0.1', '0',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->solver()->toConservativeFloat($result, 'long');
    }

    public function testShortLowerDtoBoundaryRemainsPositiveAfterOneUlp(): void
    {
        $result = new HyperliquidIsolatedLiquidationResult('0.000000000001', 0, '0', 5, '0.1', '0');

        $value = $this->solver()->toConservativeFloat($result, 'short');

        self::assertGreaterThan(0.0, $value);
        self::assertSame('9.9999999999999978e-13', sprintf('%.17g', $value));
    }

    /** @param list<HyperliquidMarginTierEvidence>|null $tiers */
    private function evidence(?array $tiers = null, int $universeMaxLeverage = 10): HyperliquidMarginSafetyEvidence
    {
        return new HyperliquidMarginSafetyEvidence(
            symbol: 'BTCUSDT',
            coin: 'BTC',
            marginTableId: 51,
            universeMaxLeverage: $universeMaxLeverage,
            tiers: $tiers ?? [
                new HyperliquidMarginTierEvidence('0', 10, '0.05', '0'),
                new HyperliquidMarginTierEvidence('10000', 5, '0.1', '500'),
            ],
            accountAddress: '0x1111111111111111111111111111111111111111',
            observedUser: '0x1111111111111111111111111111111111111111',
            observedCoin: 'BTC',
            observedMarginMode: 'isolated',
            observedLeverage: 5,
            observedAt: new \DateTimeImmutable('2026-07-12T12:00:00Z'),
        );
    }

    private function solver(): HyperliquidIsolatedLiquidationSolver
    {
        return new HyperliquidIsolatedLiquidationSolver();
    }
}
