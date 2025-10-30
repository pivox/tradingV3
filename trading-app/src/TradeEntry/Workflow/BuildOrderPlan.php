<?php
declare(strict_types=1);

namespace App\TradeEntry\Workflow;

use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Dto\{TradeEntryRequest, PreflightReport};
use App\TradeEntry\EntryZone\{EntryZoneCalculator, EntryZoneFilters};
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

    public function __invoke(TradeEntryRequest $req, PreflightReport $pre): OrderPlanModel
    {
        $this->flowLogger->info('build_order_plan.start', [
            'symbol' => $req->symbol,
            'side' => $req->side->value,
            'spread_pct' => $pre->spreadPct,
            'mode_note' => $pre->modeNote,
        ]);
        $plan = $this->box->create($req, $pre);

        $zone = $this->zones->compute($req->symbol, $req->side, $pre->pricePrecision);
        $candidate = $plan->entry;
        if ($candidate <= 0.0) {
            $candidate = $req->side === Side::Long ? $pre->bestAsk : $pre->bestBid;
        }

        $this->flowLogger->debug('build_order_plan.entry_zone', [
            'symbol' => $req->symbol,
            'side' => $req->side->value,
            'candidate' => $candidate,
            'zone_min' => $zone->min,
            'zone_max' => $zone->max,
            'rationale' => $zone->rationale,
        ]);

        if (!$zone->contains($candidate)) {
            $this->flowLogger->error('build_order_plan.entry_out_of_zone', [
                'symbol' => $req->symbol,
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
            ]);
            throw new \RuntimeException('EntryZoneFilters ont rejeté la requête');
        }

        $this->liquidation->assertSafe($plan);
        $this->flowLogger->debug('build_order_plan.liquidation_guard_ok', [
            'symbol' => $req->symbol,
            'entry' => $plan->entry,
            'sl' => $plan->sl,
            'lev' => $plan->leverage,
        ]);

        $this->positionsLogger->info('build_order_plan.ready', [
            'symbol' => $plan->symbol,
            'side' => $plan->side->value,
            'entry' => $plan->entry,
            'quantity' => $plan->quantity,
            'leverage' => $plan->leverage,
            'sl' => $plan->sl,
            'tp1' => $plan->tp1,
        ]);

        return $plan;
    }
}
