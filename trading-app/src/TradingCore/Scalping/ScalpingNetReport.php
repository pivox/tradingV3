<?php

declare(strict_types=1);

namespace App\TradingCore\Scalping;

use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\LineageContextException;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanDecimal;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanTarget;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionProof;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioException;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservation;
use Brick\Math\BigDecimal;

final readonly class ScalpingNetReport
{
    private const HASH_PATTERN = '/\Asha256:[a-f0-9]{64}\z/D';

    /** @var array<string, string> */
    private const SIDES_BY_SETUP = [
        'scalping.trend_continuation.long' => 'long',
        'scalping.pullback.long' => 'long',
        'scalping.trend_momentum.short' => 'short',
    ];

    /** @param non-empty-list<ScalpingNetReportCell> $cells */
    private function __construct(public array $cells)
    {
    }

    /**
     * The report is implementation evidence, not realized-trade certification.
     * Gross R and net R are totals sharing the canonical target net-risk
     * denominator. Cost quote is the complete entry-to-target path.
     *
     * @param list<ScalpingShadowOutcome> $outcomes
     */
    public static function fromOutcomes(array $outcomes): self
    {
        if ($outcomes === []) {
            throw new \InvalidArgumentException('scalping_net_report_outcomes_empty');
        }

        /** @var array<string, array{setup_id:string,setup_version:string,side:string,sample_count:int,gross_r:BigDecimal,net_r:BigDecimal,cost_quote:BigDecimal}> $groups */
        $groups = [];
        /** @var array<string, true> $seenDecisionKeys */
        $seenDecisionKeys = [];
        foreach ($outcomes as $outcome) {
            if (!$outcome instanceof ScalpingShadowOutcome) {
                throw new \InvalidArgumentException('scalping_net_report_outcome_invalid');
            }
            if (
                $outcome->status !== 'planned'
                || $outcome->reasonCode !== 'scalping_shadow_planned'
                || !$outcome->orderPlan instanceof CanonicalOrderPlan
                || !$outcome->reservation instanceof CanonicalPortfolioReservation
            ) {
                throw new \InvalidArgumentException('scalping_net_report_outcome_not_planned');
            }

            self::assertLineage($outcome->lineage);
            $decisionKey = (string) $outcome->lineage->decisionKey;
            if (isset($seenDecisionKeys[$decisionKey])) {
                throw new \InvalidArgumentException('scalping_net_report_decision_duplicate');
            }
            $seenDecisionKeys[$decisionKey] = true;
            self::assertPlan($outcome->orderPlan, $outcome->lineage);
            self::assertReservation($outcome->reservation, $outcome->orderPlan, $outcome->lineage);
            self::assertEvidence($outcome, $outcome->orderPlan, $outcome->reservation);

            $target = $outcome->orderPlan->targets[0];
            $key = implode('|', [
                $outcome->orderPlan->setupId,
                $outcome->orderPlan->setupVersion,
                $outcome->orderPlan->side,
            ]);
            $grossR = self::decimal($target->grossReward / $target->netRisk);
            $netR = self::decimal($target->netR);
            $costQuote = self::targetCostQuote($target);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'setup_id' => $outcome->orderPlan->setupId,
                    'setup_version' => $outcome->orderPlan->setupVersion,
                    'side' => $outcome->orderPlan->side,
                    'sample_count' => 0,
                    'gross_r' => BigDecimal::zero(),
                    'net_r' => BigDecimal::zero(),
                    'cost_quote' => BigDecimal::zero(),
                ];
            }
            ++$groups[$key]['sample_count'];
            $groups[$key]['gross_r'] = $groups[$key]['gross_r']->plus($grossR);
            $groups[$key]['net_r'] = $groups[$key]['net_r']->plus($netR);
            $groups[$key]['cost_quote'] = $groups[$key]['cost_quote']->plus($costQuote);
        }

        ksort($groups, SORT_STRING);
        $cells = [];
        foreach ($groups as $group) {
            $cells[] = new ScalpingNetReportCell(
                $group['setup_id'],
                $group['setup_version'],
                $group['side'],
                $group['sample_count'],
                $group['gross_r']->toFloat(),
                $group['net_r']->toFloat(),
                $group['cost_quote']->toFloat(),
                false,
            );
        }

        return new self($cells);
    }

    /** @return array{schema:'scalping-net-report.v1',tuning_applied:false,cells:non-empty-list<array{setup_id:string,setup_version:string,side:string,sample_count:int,gross_r:float,net_r:float,cost_quote:float,certified:false}>} */
    public function toArray(): array
    {
        return [
            'schema' => 'scalping-net-report.v1',
            'tuning_applied' => false,
            'cells' => array_map(
                static fn (ScalpingNetReportCell $cell): array => $cell->toArray(),
                $this->cells,
            ),
        ];
    }

    public function toJson(): string
    {
        $callerPrecision = ini_get('serialize_precision');
        if ($callerPrecision === false) {
            throw new \RuntimeException('scalping_net_report_json_precision_unavailable');
        }
        $restorePrecision = $callerPrecision !== '-1';
        if ($restorePrecision && ini_set('serialize_precision', '-1') === false) {
            throw new \RuntimeException('scalping_net_report_json_precision_unavailable');
        }

        try {
            $json = json_encode(
                $this->toArray(),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
            );
        } finally {
            if ($restorePrecision && ini_set('serialize_precision', $callerPrecision) === false) {
                throw new \RuntimeException('scalping_net_report_json_precision_restore_failed');
            }
        }

        return $json . "\n";
    }

    private static function assertLineage(LineageContext $lineage): void
    {
        try {
            $lineage->assertCanonicalIntegrity()->assertExecutableTradeContract();
        } catch (LineageContextException $exception) {
            $reason = \in_array($exception->getMessage(), [
                'canonical_identity_invalid:config_hash',
                'canonical_identity_mismatch:config_hash',
            ], true)
                ? 'scalping_net_report_lineage_snapshot_hash_invalid'
                : 'scalping_net_report_lineage_incomplete';

            throw new \InvalidArgumentException($reason, 0, $exception);
        }
        $requiredStrings = [
            $lineage->orchestrationRunId,
            $lineage->correlationRunId,
            $lineage->orchestrationSetId,
            $lineage->exchange,
            $lineage->environment,
            $lineage->marketType,
            $lineage->symbol,
            $lineage->decisionKey,
            $lineage->effectiveConfigReference,
        ];
        if (
            in_array(null, $requiredStrings, true)
            || in_array('', $requiredStrings, true)
        ) {
            throw new \InvalidArgumentException('scalping_net_report_lineage_incomplete');
        }

        $side = strtolower((string) $lineage->side);
        if (
            $lineage->modeId !== 'scalping'
            || $lineage->modeVersion !== '1.1.0'
            || $lineage->setupVersion !== '1.1.0'
            || (self::SIDES_BY_SETUP[(string) $lineage->setupId] ?? null) !== $side
        ) {
            throw new \InvalidArgumentException('scalping_net_report_lineage_identity_invalid');
        }

        if ($lineage->effectiveConfigSnapshot === null) {
            throw new \InvalidArgumentException('scalping_net_report_lineage_incomplete');
        }
        $snapshot = $lineage->effectiveConfigSnapshot->toArray();
        $config = $snapshot['config'] ?? null;
        if (!\is_array($config)) {
            throw new \InvalidArgumentException('scalping_net_report_lineage_incomplete');
        }
        $environment = $config['environment'] ?? null;
        if (!\is_array($environment) || ($environment['write_enabled'] ?? null) !== false) {
            throw new \InvalidArgumentException('scalping_net_report_lineage_snapshot_readonly_invalid');
        }
    }

    private static function assertPlan(CanonicalOrderPlan $plan, LineageContext $lineage): void
    {
        if (
            $plan->modeId !== $lineage->modeId
            || $plan->modeVersion !== $lineage->modeVersion
            || $plan->setupId !== $lineage->setupId
            || $plan->setupVersion !== $lineage->setupVersion
            || strtoupper($plan->side) !== $lineage->side
            || $plan->exchange !== $lineage->exchange
            || $plan->environment !== $lineage->environment
            || $plan->marketType !== $lineage->marketType
            || $plan->symbol !== $lineage->symbol
            || $plan->configHash !== $lineage->configHash
            || (self::SIDES_BY_SETUP[$plan->setupId] ?? null) !== $plan->side
            || $plan->modeId !== 'scalping'
            || $plan->modeVersion !== '1.1.0'
            || $plan->setupVersion !== '1.1.0'
        ) {
            throw new \InvalidArgumentException('scalping_net_report_plan_identity_mismatch');
        }
        try {
            CanonicalOrderPlanValidator::validateAt($plan, $plan->createdAt);
        } catch (CanonicalOrderPlanException|\JsonException $exception) {
            throw new \InvalidArgumentException('scalping_net_report_plan_invalid', 0, $exception);
        }
        if (!self::validHash($plan->planHash) || !self::validHash($plan->costInputHash) || !self::validHashes($plan->inputHashes)) {
            throw new \InvalidArgumentException('scalping_net_report_plan_hash_invalid');
        }
        if (count($plan->targets) !== 1 || $plan->targets[0]->id !== 'tp1') {
            throw new \InvalidArgumentException('scalping_net_report_plan_target_invalid');
        }

        self::assertTargetValues($plan->targets[0]);
        try {
            $expectedPlanHash = $plan->expectedPlanHash();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('scalping_net_report_plan_hash_invalid');
        }
        if (!hash_equals($expectedPlanHash, $plan->planHash)) {
            throw new \InvalidArgumentException('scalping_net_report_plan_hash_invalid');
        }
    }

    private static function assertReservation(
        CanonicalPortfolioReservation $reservation,
        CanonicalOrderPlan $plan,
        LineageContext $lineage,
    ): void {
        try {
            $reservation->assertCanonicalOpeningState($plan);
        } catch (CanonicalPortfolioException $exception) {
            throw new \InvalidArgumentException('scalping_net_report_reservation_identity_mismatch', 0, $exception);
        }
        if (
            $reservation->decisionKey !== $lineage->decisionKey
        ) {
            throw new \InvalidArgumentException('scalping_net_report_reservation_identity_mismatch');
        }
    }

    private static function assertEvidence(
        ScalpingShadowOutcome $outcome,
        CanonicalOrderPlan $plan,
        CanonicalPortfolioReservation $reservation,
    ): void {
        if (
            ($outcome->evidence['config_hash'] ?? null) !== $plan->configHash
            || ($outcome->evidence['plan_hash'] ?? null) !== $plan->planHash
            || ($outcome->evidence['reservation_hash'] ?? null) !== $reservation->stateHash
        ) {
            throw new \InvalidArgumentException('scalping_net_report_evidence_hash_mismatch');
        }
        $proofData = $outcome->evidence['admission_proof'] ?? null;
        if (!\is_array($proofData)) {
            throw new \InvalidArgumentException('scalping_net_report_admission_proof_invalid');
        }
        try {
            CanonicalPortfolioAdmissionProof::fromArray($proofData)->verify($plan, $reservation);
        } catch (CanonicalPortfolioException $exception) {
            throw new \InvalidArgumentException('scalping_net_report_admission_proof_invalid', 0, $exception);
        }
    }

    private static function assertTargetValues(CanonicalOrderPlanTarget $target): void
    {
        $values = [
            $target->grossReward,
            $target->netReward,
            $target->netRisk,
            $target->netR,
            $target->entryFee,
            $target->targetFee,
            $target->entrySpreadCost,
            $target->entrySlippageCost,
            $target->targetSpreadCost,
            $target->targetSlippageCost,
            $target->fundingCost,
        ];
        foreach ($values as $value) {
            if (!\is_finite($value)) {
                throw new \InvalidArgumentException('scalping_net_report_plan_value_invalid');
            }
        }
        if (
            $target->grossReward < 0.0
            || $target->netRisk <= 0.0
            || $target->entryFee < 0.0
            || $target->targetFee < 0.0
            || $target->entrySpreadCost < 0.0
            || $target->entrySlippageCost < 0.0
            || $target->targetSpreadCost < 0.0
            || $target->targetSlippageCost < 0.0
            || $target->fundingCost < 0.0
        ) {
            throw new \InvalidArgumentException('scalping_net_report_plan_value_invalid');
        }
    }

    private static function targetCostQuote(CanonicalOrderPlanTarget $target): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ([
            $target->entryFee,
            $target->targetFee,
            $target->entrySpreadCost,
            $target->entrySlippageCost,
            $target->targetSpreadCost,
            $target->targetSlippageCost,
            $target->fundingCost,
        ] as $cost) {
            $total = $total->plus(self::decimal($cost));
        }

        return $total;
    }

    private static function decimal(float $value): BigDecimal
    {
        try {
            return CanonicalOrderPlanDecimal::fromFloat($value, 'scalping_net_report_plan_value_invalid');
        } catch (\Throwable) {
            throw new \InvalidArgumentException('scalping_net_report_plan_value_invalid');
        }
    }

    private static function validHash(?string $hash): bool
    {
        return $hash !== null && preg_match(self::HASH_PATTERN, $hash) === 1;
    }

    /** @param list<string> $hashes */
    private static function validHashes(array $hashes): bool
    {
        if ($hashes === [] || count(array_unique($hashes)) !== count($hashes)) {
            return false;
        }
        foreach ($hashes as $hash) {
            if (!self::validHash($hash)) {
                return false;
            }
        }

        return true;
    }
}
