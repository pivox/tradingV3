<?php
declare(strict_types=1);

namespace App\TradeEntry\OrderPlan;

use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Dto\EntryZone;
use App\TradeEntry\Dto\RiskDecision;
 
final class OrderPlanBuilder
{
    public function build(TradeEntryRequest $req, EntryZone $zone, RiskDecision $risk): OrderPlanModel
    {
        $slDistance = $req->kStopAtr * $req->atrValue;
        $sl = match ($req->side->value) {
            'long'  => $req->entryPriceBase - $slDistance,
            'short' => $req->entryPriceBase + $slDistance,
        };

        // TP1 = entry ± (R * slDistance)
        $tp1 = match ($req->side->value) {
            'long'  => $req->entryPriceBase + ($req->tp1R * $slDistance),
            'short' => $req->entryPriceBase - ($req->tp1R * $slDistance),
        };

        return new OrderPlanModel(
            symbol:     $req->symbol,
            side:        $req->side,
            entryPrice: $req->entryPriceBase,
            quantity:   $risk->quantity,
            slPrice:    $sl,
            tp1Price:   $tp1,
            tp1SizePct: $req->tp1SizePct
        );
    }
}
