<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Scalping;

use App\Trading\Lineage\LineageContext;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;
use App\TradingCore\Scalping\ScalpingNetReport;
use App\TradingCore\Scalping\ScalpingNetReportCell;
use App\TradingCore\Scalping\ScalpingShadowRequest;
use App\TradingCore\Scalping\ScalpingShadowOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScalpingNetReport::class)]
#[CoversClass(ScalpingNetReportCell::class)]
final class ScalpingNetReportTest extends TestCase
{
    public function testGroupsExactModernPlansBySetupVersionAndSideWithoutCrossSetupAggregation(): void
    {
        $outcomes = self::plannedOutcomes();

        $report = ScalpingNetReport::fromOutcomes($outcomes);

        self::assertCount(3, $report->cells);
        self::assertSame([
            'scalping.pullback.long|1.1.0|long',
            'scalping.trend_continuation.long|1.1.0|long',
            'scalping.trend_momentum.short|1.1.0|short',
        ], array_map(
            static fn (ScalpingNetReportCell $cell): string => implode('|', [
                $cell->setupId,
                $cell->setupVersion,
                $cell->side,
            ]),
            $report->cells,
        ));

        foreach ($report->cells as $index => $cell) {
            $outcome = match ($index) {
                0 => $outcomes[1],
                1 => $outcomes[0],
                2 => $outcomes[2],
                default => throw new \LogicException('Unexpected report cell index.'),
            };
            $plan = $outcome->orderPlan;
            self::assertNotNull($plan);
            $target = $plan->targets[0];
            $expectedSampleCount = $index === 1 ? 2 : 1;

            // Gross and net R share the canonical target net-risk denominator. Quote
            // cost is the complete entry-to-target cost path, each counted once.
            self::assertSame(($target->grossReward / $target->netRisk) * $expectedSampleCount, $cell->grossR);
            self::assertSame($target->netR * $expectedSampleCount, $cell->netR);
            $expectedCost = \Brick\Math\BigDecimal::zero();
            foreach ([
                $target->entryFee,
                $target->targetFee,
                $target->entrySpreadCost,
                $target->entrySlippageCost,
                $target->targetSpreadCost,
                $target->targetSlippageCost,
                $target->fundingCost,
            ] as $cost) {
                $expectedCost = $expectedCost->plus(CanonicalOrderPlanDecimal::fromFloat($cost, 'test_cost_invalid'));
            }
            self::assertSame($expectedCost->multipliedBy((string) $expectedSampleCount)->toFloat(), $cell->costQuote);
            self::assertSame($expectedSampleCount, $cell->sampleCount);
            self::assertFalse($cell->certified);
        }
    }

    public function testSerializationIsSortedOrderIndependentIdempotentAndMatchesFrozenFixtureByteForByte(): void
    {
        $outcomes = self::plannedOutcomes();
        $forward = ScalpingNetReport::fromOutcomes($outcomes);
        $reverse = ScalpingNetReport::fromOutcomes(array_reverse($outcomes));

        self::assertSame($forward->toArray(), $reverse->toArray());
        self::assertSame($forward->toJson(), $reverse->toJson());
        self::assertSame($forward->toJson(), $forward->toJson());
        self::assertSame([
            'schema' => 'scalping-net-report.v1',
            'tuning_applied' => false,
            'cells' => array_map(
                static fn (ScalpingNetReportCell $cell): array => $cell->toArray(),
                $forward->cells,
            ),
        ], $forward->toArray());
        self::assertSame(
            file_get_contents(dirname(__DIR__, 2) . '/Fixtures/TradingCore/Scalping/scalping-net-report.json'),
            $forward->toJson(),
        );
        self::assertSame(
            $forward->toArray(),
            json_decode($forward->toJson(), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertFalse($forward->toArray()['tuning_applied']);
        self::assertSame([false, false, false], array_column($forward->toArray()['cells'], 'certified'));
    }

    public function testRejectsNonOutcomesNoTradeAndEmptyEvidenceSets(): void
    {
        $planned = self::plannedOutcomes()[0];
        $noTrade = new ScalpingShadowOutcome('no_trade', 'rejected', $planned->lineage, null, null, []);
        $wrongReason = new ScalpingShadowOutcome(
            'planned',
            'foreign_planned',
            $planned->lineage,
            $planned->orderPlan,
            $planned->reservation,
            $planned->evidence,
        );

        foreach ([
            [[new \stdClass()], 'scalping_net_report_outcome_invalid'],
            [[$noTrade], 'scalping_net_report_outcome_not_planned'],
            [[$wrongReason], 'scalping_net_report_outcome_not_planned'],
            [[], 'scalping_net_report_outcomes_empty'],
        ] as [$outcomes, $message]) {
            try {
                ScalpingNetReport::fromOutcomes($outcomes);
                self::fail('Malformed report evidence was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testRejectsARepeatedDecisionInsteadOfInflatingItsSampleCount(): void
    {
        $planned = self::plannedOutcomes()[0];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scalping_net_report_decision_duplicate');
        ScalpingNetReport::fromOutcomes([$planned, $planned]);
    }

    public function testRejectsIncompleteLineageAndEveryPlanReservationHashMismatch(): void
    {
        $planned = self::plannedOutcomes()[0];
        self::assertNotNull($planned->orderPlan);
        self::assertNotNull($planned->reservation);

        $missingReference = self::mutateSerializedValue(
            $planned->lineage,
            serialize($planned->lineage->effectiveConfigReference),
            'N;',
            LineageContext::class,
        );
        $foreignSetupPlan = self::mutateSerializedValue(
            $planned->orderPlan,
            serialize($planned->orderPlan->setupId),
            serialize('scalping.pullback.long'),
            CanonicalOrderPlan::class,
        );
        $badConfigPlan = self::mutateSerializedValue(
            $planned->orderPlan,
            serialize($planned->orderPlan->configHash),
            serialize('sha256:' . str_repeat('b', 64)),
            CanonicalOrderPlan::class,
        );
        $badPlanHashPlan = self::mutateSerializedValue(
            $planned->orderPlan,
            serialize($planned->orderPlan->planHash),
            serialize('sha256:' . str_repeat('c', 64)),
            CanonicalOrderPlan::class,
        );
        $badReservation = self::mutateSerializedValue(
            $planned->reservation,
            serialize($planned->reservation->planHash),
            serialize('sha256:' . str_repeat('d', 64)),
            $planned->reservation::class,
        );
        $badReservationState = self::mutateSerializedValue(
            $planned->reservation,
            serialize($planned->reservation->stateHash),
            serialize('sha256:' . str_repeat('f', 64)),
            $planned->reservation::class,
        );
        $badEvidence = $planned->evidence;
        $badEvidence['plan_hash'] = 'sha256:' . str_repeat('e', 64);

        foreach ([
            [new ScalpingShadowOutcome('planned', $planned->reasonCode, $missingReference, $planned->orderPlan, $planned->reservation, $planned->evidence), 'scalping_net_report_lineage_incomplete'],
            [new ScalpingShadowOutcome('planned', $planned->reasonCode, $planned->lineage, $foreignSetupPlan, $planned->reservation, $planned->evidence), 'scalping_net_report_plan_identity_mismatch'],
            [new ScalpingShadowOutcome('planned', $planned->reasonCode, $planned->lineage, $badConfigPlan, $planned->reservation, $planned->evidence), 'scalping_net_report_plan_identity_mismatch'],
            [new ScalpingShadowOutcome('planned', $planned->reasonCode, $planned->lineage, $badPlanHashPlan, $planned->reservation, $planned->evidence), 'scalping_net_report_plan_hash_invalid'],
            [new ScalpingShadowOutcome('planned', $planned->reasonCode, $planned->lineage, $planned->orderPlan, $badReservation, $planned->evidence), 'scalping_net_report_reservation_identity_mismatch'],
            [new ScalpingShadowOutcome('planned', $planned->reasonCode, $planned->lineage, $planned->orderPlan, $badReservationState, $planned->evidence), 'scalping_net_report_reservation_identity_mismatch'],
            [new ScalpingShadowOutcome('planned', $planned->reasonCode, $planned->lineage, $planned->orderPlan, $planned->reservation, $badEvidence), 'scalping_net_report_evidence_hash_mismatch'],
        ] as [$outcome, $message]) {
            try {
                ScalpingNetReport::fromOutcomes([$outcome]);
                self::fail('Contradictory modern evidence was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testRejectsNonFiniteCanonicalTargetValues(): void
    {
        $planned = self::plannedOutcomes()[0];
        self::assertNotNull($planned->orderPlan);
        $target = $planned->orderPlan->targets[0];
        $nonFinitePlan = self::mutateSerializedValue(
            $planned->orderPlan,
            serialize($target->netR),
            'd:NAN;',
            CanonicalOrderPlan::class,
        );

        $outcome = new ScalpingShadowOutcome(
            'planned',
            $planned->reasonCode,
            $planned->lineage,
            $nonFinitePlan,
            $planned->reservation,
            $planned->evidence,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scalping_net_report_plan_value_invalid');
        ScalpingNetReport::fromOutcomes([$outcome]);
    }

    /** @return iterable<string, array{string, string, string, int, float, float, float, bool}> */
    public static function invalidCells(): iterable
    {
        yield 'unknown setup' => ['unknown', '1.1.0', 'long', 1, 1.0, 1.0, 0.1, false];
        yield 'wrong version' => ['scalping.pullback.long', '1.0.0', 'long', 1, 1.0, 1.0, 0.1, false];
        yield 'wrong side' => ['scalping.pullback.long', '1.1.0', 'short', 1, 1.0, 1.0, 0.1, false];
        yield 'empty sample' => ['scalping.pullback.long', '1.1.0', 'long', 0, 1.0, 1.0, 0.1, false];
        yield 'non finite gross' => ['scalping.pullback.long', '1.1.0', 'long', 1, NAN, 1.0, 0.1, false];
        yield 'non finite net' => ['scalping.pullback.long', '1.1.0', 'long', 1, 1.0, INF, 0.1, false];
        yield 'negative gross' => ['scalping.pullback.long', '1.1.0', 'long', 1, -1.0, 1.0, 0.1, false];
        yield 'negative net' => ['scalping.pullback.long', '1.1.0', 'long', 1, 1.0, -1.0, 0.1, false];
        yield 'negative cost' => ['scalping.pullback.long', '1.1.0', 'long', 1, 1.0, 1.0, -0.1, false];
        yield 'certification forbidden' => ['scalping.pullback.long', '1.1.0', 'long', 1, 1.0, 1.0, 0.1, true];
    }

    #[DataProvider('invalidCells')]
    public function testCellShapeIsStrictAndNeverAcceptsCertification(
        string $setupId,
        string $setupVersion,
        string $side,
        int $sampleCount,
        float $grossR,
        float $netR,
        float $costQuote,
        bool $certified,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scalping_net_report_cell_invalid');

        new ScalpingNetReportCell(
            $setupId,
            $setupVersion,
            $side,
            $sampleCount,
            $grossR,
            $netR,
            $costQuote,
            $certified,
        );
    }

    /** @return list<ScalpingShadowOutcome> */
    private static function plannedOutcomes(): array
    {
        return [
            ScalpingShadowRuntimeTest::fixtureRuntime()->run(
                ScalpingShadowRuntimeTest::fixtureRequest('scalping.trend_continuation.long', 'long'),
            ),
            ScalpingShadowRuntimeTest::fixtureRuntime()->run(
                ScalpingShadowRuntimeTest::fixtureRequest('scalping.pullback.long', 'long'),
            ),
            ScalpingShadowRuntimeTest::fixtureRuntime()->run(
                ScalpingShadowRuntimeTest::fixtureRequest('scalping.trend_momentum.short', 'short'),
            ),
            self::plannedOutcomeWithDecisionKey(
                'scalping.trend_continuation.long',
                'long',
                'decision-scalping-shadow-trend-continuation-sample-2',
            ),
        ];
    }

    private static function plannedOutcomeWithDecisionKey(
        string $setupId,
        string $side,
        string $decisionKey,
    ): ScalpingShadowOutcome {
        $request = ScalpingShadowRuntimeTest::fixtureRequest($setupId, $side);
        $lineage = self::mutateSerializedValue(
            $request->lineage,
            serialize($request->lineage->decisionKey),
            serialize($decisionKey),
            LineageContext::class,
        );

        return ScalpingShadowRuntimeTest::fixtureRuntime()->run(new ScalpingShadowRequest(
            $request->configRequest,
            $lineage,
            $request->indicatorsByTimeframe,
            $request->orderPlanRequest,
            $request->portfolioScope,
            $request->portfolioSnapshot,
            $decisionKey,
            $request->liveSpreadBps,
            $request->estimatedSlippageBps,
        ));
    }

    /**
     * @template T of object
     * @param T               $object
     * @param class-string<T> $class
     * @return T
     */
    private static function mutateSerializedValue(object $object, string $from, string $to, string $class): object
    {
        $serialized = serialize($object);
        $position = strpos($serialized, $from);
        self::assertNotFalse($position, 'Mutation source was not found in serialized fixture.');
        $serialized = substr_replace($serialized, $to, $position, strlen($from));
        $mutated = unserialize($serialized, ['allowed_classes' => true]);
        self::assertInstanceOf($class, $mutated);

        return $mutated;
    }
}
