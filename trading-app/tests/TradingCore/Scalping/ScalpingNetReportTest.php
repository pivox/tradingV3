<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Scalping;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Lineage\LineageContext;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservation;
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

    public function testJsonSerializationIgnoresAndRestoresCallerSerializePrecision(): void
    {
        $report = ScalpingNetReport::fromOutcomes(self::plannedOutcomes());
        $originalPrecision = ini_get('serialize_precision');
        self::assertNotFalse($originalPrecision);
        self::assertNotFalse(ini_set('serialize_precision', '3'));

        try {
            $json = $report->toJson();

            self::assertSame('3', ini_get('serialize_precision'));
            self::assertSame(
                file_get_contents(dirname(__DIR__, 2) . '/Fixtures/TradingCore/Scalping/scalping-net-report.json'),
                $json,
            );
        } finally {
            self::assertNotFalse(ini_set('serialize_precision', $originalPrecision));
        }

        self::assertSame($originalPrecision, ini_get('serialize_precision'));
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

    public function testRejectsSnapshotConfigMutationWhenStoredHashesWereNotRecomputed(): void
    {
        $planned = self::plannedOutcomes()[0];
        $mutatedLineage = self::mutateSerializedValue(
            $planned->lineage,
            's:13:"write_enabled";b:0;',
            's:13:"write_enabled";b:1;',
            LineageContext::class,
        );
        $outcome = new ScalpingShadowOutcome(
            'planned',
            $planned->reasonCode,
            $mutatedLineage,
            $planned->orderPlan,
            $planned->reservation,
            $planned->evidence,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scalping_net_report_lineage_snapshot_hash_invalid');
        ScalpingNetReport::fromOutcomes([$outcome]);
    }

    public function testRejectsWriteEnabledSnapshotEvenWhenEveryDependentHashWasRecomputed(): void
    {
        $planned = self::plannedOutcomes()[0];
        self::assertNotNull($planned->orderPlan);
        self::assertNotNull($planned->reservation);
        $forgedLineage = self::mutateSerializedValue(
            $planned->lineage,
            's:13:"write_enabled";b:0;',
            's:13:"write_enabled";b:1;',
            LineageContext::class,
        );
        self::assertNotNull($forgedLineage->effectiveConfigSnapshot);
        $snapshot = $forgedLineage->effectiveConfigSnapshot->toArray();
        $newConfigHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash(
            $snapshot['config'],
            $snapshot['condition_catalog_hash'],
        );
        $forgedLineage = self::mutateEverySerializedValue(
            $forgedLineage,
            serialize($planned->lineage->configHash),
            serialize($newConfigHash),
            LineageContext::class,
        );
        $forgedPlan = self::mutateSerializedValue(
            $planned->orderPlan,
            serialize($planned->orderPlan->configHash),
            serialize($newConfigHash),
            CanonicalOrderPlan::class,
        );
        $forgedPlan = self::mutateSerializedValue(
            $forgedPlan,
            serialize($planned->orderPlan->planHash),
            serialize($forgedPlan->expectedPlanHash()),
            CanonicalOrderPlan::class,
        );
        $forgedReservation = self::mutateSerializedValue(
            $planned->reservation,
            serialize($planned->reservation->configHash),
            serialize($newConfigHash),
            $planned->reservation::class,
        );
        $forgedReservation = self::mutateSerializedValue(
            $forgedReservation,
            serialize($planned->reservation->planHash),
            serialize($forgedPlan->planHash),
            $planned->reservation::class,
        );
        $forgedReservation = self::mutateSerializedValue(
            $forgedReservation,
            serialize($planned->reservation->stateHash),
            serialize($forgedReservation->expectedStateHash()),
            $planned->reservation::class,
        );
        $forgedEvidence = $planned->evidence;
        $forgedEvidence['config_hash'] = $newConfigHash;
        $forgedEvidence['plan_hash'] = $forgedPlan->planHash;
        $forgedEvidence['reservation_hash'] = $forgedReservation->stateHash;
        $outcome = new ScalpingShadowOutcome(
            'planned',
            $planned->reasonCode,
            $forgedLineage,
            $forgedPlan,
            $forgedReservation,
            $forgedEvidence,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scalping_net_report_lineage_snapshot_readonly_invalid');
        ScalpingNetReport::fromOutcomes([$outcome]);
    }

    public function testCanonicalPlanValidationRejectsCoordinatedRewardAndHashForgery(): void
    {
        $planned = self::plannedOutcomes()[0];
        self::assertNotNull($planned->orderPlan);
        self::assertNotNull($planned->reservation);
        $target = $planned->orderPlan->targets[0];
        $forgedPlan = self::mutateSerializedValue(
            $planned->orderPlan,
            serialize($target->grossReward),
            serialize($target->grossReward + 0.01),
            CanonicalOrderPlan::class,
        );
        $forgedPlan = self::mutateSerializedValue(
            $forgedPlan,
            serialize($planned->orderPlan->planHash),
            serialize($forgedPlan->expectedPlanHash()),
            CanonicalOrderPlan::class,
        );
        $forgedReservation = self::mutateSerializedValue(
            $planned->reservation,
            serialize($planned->reservation->planHash),
            serialize($forgedPlan->planHash),
            $planned->reservation::class,
        );
        $forgedReservation = self::mutateSerializedValue(
            $forgedReservation,
            serialize($planned->reservation->stateHash),
            serialize($forgedReservation->expectedStateHash()),
            $planned->reservation::class,
        );
        $forgedEvidence = $planned->evidence;
        $forgedEvidence['plan_hash'] = $forgedPlan->planHash;
        $forgedEvidence['reservation_hash'] = $forgedReservation->stateHash;
        $outcome = new ScalpingShadowOutcome(
            'planned',
            $planned->reasonCode,
            $planned->lineage,
            $forgedPlan,
            $forgedReservation,
            $forgedEvidence,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scalping_net_report_plan_invalid');
        ScalpingNetReport::fromOutcomes([$outcome]);
    }

    /** @return iterable<string, array{string}> */
    public static function coordinatedReservationForgeryCases(): iterable
    {
        yield 'planned quantity' => ['planned_quantity'];
        yield 'stop cost' => ['stop_cost'];
        yield 'entry deadline' => ['entry_deadline'];
        yield 'decimal mirror' => ['decimal_mirror'];
        yield 'protected state' => ['protected_state'];
        yield 'version' => ['version'];
        yield 'applied fills' => ['applied_fills'];
        yield 'scope quote currency' => ['scope_quote_currency'];
        yield 'decision key pattern' => ['decision_key_pattern'];
        yield 'portfolio source pattern' => ['portfolio_source_pattern'];
        yield 'portfolio source semver' => ['portfolio_source_semver'];
        yield 'portfolio input hash format' => ['portfolio_input_hash'];
        yield 'portfolio identity hash format' => ['portfolio_identity_hash'];
        yield 'admission hash format' => ['admission_hash'];
    }

    #[DataProvider('coordinatedReservationForgeryCases')]
    public function testRejectsCoordinatedRehashedReservationThatIsNotExactOpeningState(string $case): void
    {
        $planned = self::plannedOutcomes()[0];
        self::assertNotNull($planned->orderPlan);
        self::assertNotNull($planned->reservation);
        $plan = $planned->orderPlan;
        $reservation = $planned->reservation;
        $forged = match ($case) {
            'planned_quantity' => self::mutateSerializedProperty(
                $reservation,
                'plannedQuantity',
                $reservation->plannedQuantity,
                $reservation->plannedQuantity + $plan->quantityStep,
                CanonicalPortfolioReservation::class,
            ),
            'stop_cost' => self::mutateSerializedProperty(
                $reservation,
                'stopFeeRate',
                $reservation->stopFeeRate,
                $reservation->stopFeeRate + 0.001,
                CanonicalPortfolioReservation::class,
            ),
            'entry_deadline' => self::mutateSerializedProperty(
                $reservation,
                'entryExpiresAt',
                $reservation->entryExpiresAt,
                $reservation->entryExpiresAt->modify('+1 second'),
                CanonicalPortfolioReservation::class,
            ),
            'decimal_mirror' => self::mutateSerializedProperty(
                $reservation,
                'remainingQuantityDecimal',
                $reservation->remainingQuantityDecimal,
                '999',
                CanonicalPortfolioReservation::class,
            ),
            'protected_state' => self::mutateSerializedProperty(
                $reservation,
                'protectedQuantity',
                $reservation->protectedQuantity,
                $plan->quantityStep,
                CanonicalPortfolioReservation::class,
            ),
            'version' => self::mutateSerializedProperty(
                $reservation,
                'version',
                $reservation->version,
                2,
                CanonicalPortfolioReservation::class,
            ),
            'applied_fills' => self::mutateSerializedProperty(
                $reservation,
                'appliedFillHashes',
                $reservation->appliedFillHashes,
                ['forged-fill' => 'sha256:' . str_repeat('a', 64)],
                CanonicalPortfolioReservation::class,
            ),
            'scope_quote_currency' => self::mutateSerializedProperty(
                $reservation,
                'quoteCurrency',
                $reservation->scope->quoteCurrency,
                'USDC',
                CanonicalPortfolioReservation::class,
            ),
            'decision_key_pattern' => self::mutateSerializedProperty(
                $reservation,
                'decisionKey',
                $reservation->decisionKey,
                'bad decision key',
                CanonicalPortfolioReservation::class,
            ),
            'portfolio_source_pattern' => self::mutateSerializedProperty(
                $reservation,
                'portfolioSource',
                $reservation->portfolioSource,
                'Bad Source',
                CanonicalPortfolioReservation::class,
            ),
            'portfolio_source_semver' => self::mutateSerializedProperty(
                $reservation,
                'portfolioSourceVersion',
                $reservation->portfolioSourceVersion,
                'latest',
                CanonicalPortfolioReservation::class,
            ),
            'portfolio_input_hash' => self::mutateEverySerializedValue(
                $reservation,
                serialize($reservation->portfolioInputHash),
                serialize('invalid'),
                CanonicalPortfolioReservation::class,
            ),
            'portfolio_identity_hash' => self::mutateSerializedProperty(
                $reservation,
                'portfolioSnapshotIdentityHash',
                $reservation->portfolioSnapshotIdentityHash,
                'invalid',
                CanonicalPortfolioReservation::class,
            ),
            'admission_hash' => self::mutateSerializedProperty(
                $reservation,
                'admissionHash',
                $reservation->admissionHash,
                'invalid',
                CanonicalPortfolioReservation::class,
            ),
            default => throw new \LogicException('Unknown reservation forgery case.'),
        };
        $forged = self::rehashReservation($forged);
        $evidence = $planned->evidence;
        $evidence['reservation_hash'] = $forged->stateHash;
        $outcome = new ScalpingShadowOutcome(
            'planned',
            $planned->reasonCode,
            $planned->lineage,
            $plan,
            $forged,
            $evidence,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scalping_net_report_reservation_identity_mismatch');
        ScalpingNetReport::fromOutcomes([$outcome]);
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
            [new ScalpingShadowOutcome('planned', $planned->reasonCode, $planned->lineage, $badPlanHashPlan, $planned->reservation, $planned->evidence), 'scalping_net_report_plan_invalid'],
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
        $this->expectExceptionMessage('scalping_net_report_plan_invalid');
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

    /**
     * @template T of object
     * @param T               $object
     * @param class-string<T> $class
     * @return T
     */
    private static function mutateSerializedProperty(
        object $object,
        string $property,
        mixed $from,
        mixed $to,
        string $class,
    ): object {
        return self::mutateSerializedValue(
            $object,
            serialize($property) . serialize($from),
            serialize($property) . serialize($to),
            $class,
        );
    }

    private static function rehashReservation(CanonicalPortfolioReservation $reservation): CanonicalPortfolioReservation
    {
        return self::mutateSerializedProperty(
            $reservation,
            'stateHash',
            $reservation->stateHash,
            $reservation->expectedStateHash(),
            CanonicalPortfolioReservation::class,
        );
    }

    /**
     * @template T of object
     * @param T               $object
     * @param class-string<T> $class
     * @return T
     */
    private static function mutateEverySerializedValue(object $object, string $from, string $to, string $class): object
    {
        $serialized = serialize($object);
        $serialized = str_replace($from, $to, $serialized, $replacements);
        self::assertGreaterThan(0, $replacements, 'Mutation source was not found in serialized fixture.');
        $mutated = unserialize($serialized, ['allowed_classes' => true]);
        self::assertInstanceOf($class, $mutated);

        return $mutated;
    }
}
