<?php
declare(strict_types=1);

namespace App\TradeEntry\Workflow;

use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Dto\{TradeEntryRequest, PreflightReport};
use App\TradeEntry\EntryZone\{EntryZoneCalculator, EntryZoneFilters, EntryZone};
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
        #[Autowire(service: 'monolog.logger.positions_flow')] private readonly LoggerInterface $flowLogger,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {}

    public function __invoke(TradeEntryRequest $req, PreflightReport $pre, ?string $decisionKey = null): OrderPlanModel
    {
        $this->flowLogger->info('build_order_plan.start', [
            'symbol' => $req->symbol,
            'side' => $req->side->value,
            'spread_pct' => $pre->spreadPct,
            'mode_note' => $pre->modeNote,
            'decision_key' => $decisionKey,
        ]);

        // Compute entry zone first, then create the plan with zone so builder can clamp entry if needed
        $zone = $this->zones->compute($req->symbol, $req->side, $pre->pricePrecision, $decisionKey);
        $plan = $this->box->create($req, $pre, $decisionKey, $zone);
        $candidate = $plan->entry > 0.0 ? $plan->entry : ($req->side === Side::Long ? $pre->bestAsk : $pre->bestBid);

        $this->flowLogger->debug('build_order_plan.entry_zone', [
            'symbol' => $req->symbol,
            'side' => $req->side->value,
            'decision_key' => $decisionKey,
            'candidate' => $candidate,
            'zone_min' => $zone->min,
            'zone_max' => $zone->max,
            'rationale' => $zone->rationale,
        ]);

        // After clamping inside builder, entry should be within zone. If not, log and fail fast.
        if (!$zone->contains($candidate)) {
            $this->flowLogger->error('build_order_plan.entry_out_of_zone_after_clamp', [
                'symbol' => $req->symbol,
                'decision_key' => $decisionKey,
                'candidate' => $candidate,
                'zone_min' => $zone->min,
                'zone_max' => $zone->max,
            ]);
            throw new \RuntimeException('Prix d\'entrée hors zone calculée');
        }

        $context = [
            'request' => $req,
            'preflight' => $pre,
            'plan' => $plan,
            'zone' => $zone,
        ];
        if (!$this->filters->passAll($context)) {
            $this->flowLogger->error('build_order_plan.filters_rejected', [
                'symbol' => $req->symbol,
                'side' => $req->side->value,
                'decision_key' => $decisionKey,
            ]);
            throw new \RuntimeException('EntryZoneFilters ont rejeté la requête');
        }

        $this->liquidation->assertSafe($plan);
        $this->flowLogger->debug('build_order_plan.liquidation_guard_ok', [
            'symbol' => $req->symbol,
            'decision_key' => $decisionKey,
            'entry' => $plan->entry,
            'stop' => $plan->stop,
            'lev' => $plan->leverage,
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
        ]);

        return $plan;
    }
}
