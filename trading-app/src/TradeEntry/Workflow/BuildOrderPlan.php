<?php
declare(strict_types=1);

namespace App\TradeEntry\Workflow;

use App\TradeEntry\Dto\{TradeEntryRequest, OrderPlanModel, PreflightReport};
use App\TradeEntry\EntryZone\{EntryZoneCalculator, EntryZoneFilters};
use App\TradeEntry\OrderPlan\OrderPlanBox;
use App\TradeEntry\Policy\LiquidationGuard;
use App\TradeEntry\Types\Side;

final class BuildOrderPlan
{
    public function __construct(
        private readonly OrderPlanBox $box,
        private readonly EntryZoneCalculator $zones,
        private readonly EntryZoneFilters $filters,
        private readonly LiquidationGuard $liquidation,
    ) {}

    public function __invoke(TradeEntryRequest $req, PreflightReport $pre): OrderPlanModel
    {
        $plan = $this->box->create($req, $pre);

        $zone = $this->zones->compute($req->symbol);
        $candidate = $plan->entry;
        if ($candidate <= 0.0) {
            $candidate = $req->side === Side::Long ? $pre->bestAsk : $pre->bestBid;
        }

        if (!$zone->contains($candidate)) {
            throw new \RuntimeException('Prix d\'entrée hors zone calculée');
        }

        $context = [
            'request' => $req,
            'preflight' => $pre,
            'plan' => $plan,
            'zone' => $zone,
        ];
        if (!$this->filters->passAll($context)) {
            throw new \RuntimeException('EntryZoneFilters ont rejeté la requête');
        }

        $this->liquidation->assertSafe($plan);

        return $plan;
    }
}
