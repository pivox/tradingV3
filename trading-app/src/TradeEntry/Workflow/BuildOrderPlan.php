<?php
declare(strict_types=1);

namespace App\TradeEntry\Workflow;

use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Dto\{TradeEntryRequest, PreflightReport};
use App\TradeEntry\EntryZone\{EntryZoneCalculator, EntryZoneFilters, EntryZone};
use App\TradeEntry\Exception\EntryZoneOutOfBoundsException;
use App\TradeEntry\OrderPlan\OrderPlanBox;
use App\TradeEntry\Policy\LiquidationGuard;
use App\TradeEntry\Types\Side;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class BuildOrderPlan
{
    public function __construct(
        private readonly OrderPlanBox $box,
        private readonly EntryZoneCalculator $zones,
        private readonly EntryZoneFilters $filters,
        private readonly LiquidationGuard $liquidation,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {}

    public function __invoke(TradeEntryRequest $req, PreflightReport $pre, ?string $decisionKey = null): OrderPlanModel
    {
        $this->positionsLogger->info('build_order_plan.start', [
            'symbol' => $req->symbol,
            'side' => $req->side->value,
            'spread_pct' => $pre->spreadPct,
            'mode_note' => $pre->modeNote,
            'decision_key' => $decisionKey,
            'reason' => 'build_order_plan',
        ]);

        // Compute entry zone first, then create the plan with zone so builder can clamp entry if needed
        $zone = $this->zones->compute($req->symbol, $req->side, $pre->pricePrecision, $decisionKey);
        $plan = $this->box->create($req, $pre, $decisionKey, $zone);
        $candidate = $plan->entry > 0.0 ? $plan->entry : ($req->side === Side::Long ? $pre->bestAsk : $pre->bestBid);

        $this->positionsLogger->debug('build_order_plan.entry_zone', [
            'symbol' => $req->symbol,
            'side' => $req->side->value,
            'decision_key' => $decisionKey,
            'candidate' => $candidate,
            'entry_candidate' => $candidate,
            'zone_min' => $zone->min,
            'zone_max' => $zone->max,
            'rationale' => $zone->rationale,
            'reason' => 'zone_evaluated',
        ]);

        // After clamping inside builder, entry should be within zone. If not, decide whether to skip or fail fast.
        if (!$zone->contains($candidate)) {
            $mark = (float)($pre->markPrice ?? $pre->bestAsk ?? $pre->bestBid ?? 0.0);
            $zoneDeviation = $mark > 0.0
                ? max(abs($zone->min - $mark), abs($zone->max - $mark)) / $mark
                : null;
            $zoneMaxDeviationPct = $this->normalizePercent($req->zoneMaxDeviationPct ?? 0.007);

            if ($zoneDeviation !== null && $zoneDeviation > $zoneMaxDeviationPct) {
                // Cas nominal : la zone est trop éloignée du marché, on skippe proprement
                $context = [
                    'symbol' => $req->symbol,
                    'decision_key' => $decisionKey,
                    'candidate' => $candidate,
                    'zone_min' => $zone->min,
                    'zone_max' => $zone->max,
                    'zone_dev_pct' => $zoneDeviation,
                    'zone_max_dev_pct' => $zoneMaxDeviationPct,
                ];

                $this->positionsLogger->warning('build_order_plan.zone_skipped_for_execution', $context + [
                    'entry_candidate' => $candidate,
                    'reason' => 'zone_far_from_market',
                ]);

                throw new EntryZoneOutOfBoundsException(
                    message: 'Entry zone out of bounds (skipped_out_of_zone)',
                    context: $context
                );
            }

            // Cas anormal : le marché est proche de la zone mais le candidat est hors zone → bug logique
            $this->positionsLogger->error('build_order_plan.entry_out_of_zone_after_clamp', [
                'symbol' => $req->symbol,
                'decision_key' => $decisionKey,
                'candidate' => $candidate,
                'entry_candidate' => $candidate,
                'zone_min' => $zone->min,
                'zone_max' => $zone->max,
                'zone_dev_pct' => $zoneDeviation,
                'zone_max_dev_pct' => $zoneMaxDeviationPct,
                'reason' => 'entry_not_within_zone',
            ]);

            throw new \RuntimeException('Prix d\'entrée hors zone calculée');
        }

        $context = [
            'request' => $req,
            'preflight' => $pre,
            'plan' => $plan,
            'zone' => $zone,
            'decision_key' => $decisionKey,
        ];
        if (!$this->filters->passAll($context)) {
            $this->positionsLogger->error('build_order_plan.filters_rejected', [
                'symbol' => $req->symbol,
                'side' => $req->side->value,
                'decision_key' => $decisionKey,
                'reason' => 'entry_zone_filters_rejection',
            ]);
            throw new \RuntimeException('EntryZoneFilters ont rejeté la requête');
        }

        $this->liquidation->assertSafe($plan);
        $this->positionsLogger->debug('build_order_plan.liquidation_guard_ok', [
            'symbol' => $req->symbol,
            'decision_key' => $decisionKey,
            'entry' => $plan->entry,
            'stop' => $plan->stop,
            'lev' => $plan->leverage,
            'leverage' => $plan->leverage,
            'reason' => 'liquidation_guard_passed',
        ]);

        $this->positionsLogger->info('build_order_plan.ready', [
            'symbol' => $plan->symbol,
            'side' => $plan->side->value,
            'entry' => $plan->entry,
            'size' => $plan->size,
            'leverage' => $plan->leverage,
            'stop' => $plan->stop,
            'take_profit' => $plan->takeProfit,
            'decision_key' => $decisionKey,
            'reason' => 'plan_ready_for_execution',
        ]);

        return $plan;
    }

    private function normalizePercent(float $value): float
    {
        $value = max(0.0, $value);
        if ($value > 1.0) {
            $value *= 0.01;
        }

        return min($value, 1.0);
    }
}
