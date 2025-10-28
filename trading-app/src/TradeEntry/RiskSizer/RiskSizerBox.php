<?php
declare(strict_types=1);

namespace App\TradeEntry\RiskSizer;

use App\TradeEntry\Dto\RiskDecision;
use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Dto\EntryZone;
 
final class RiskSizerBox
{
    public function __construct(private LeverageCalculator $levCalc) {}

    public function compute(TradeEntryRequest $req, EntryZone $zone): RiskDecision
    {
        // Stop en prix selon side (SL sous la zone pour long, au-dessus pour short).
        $slDistance = $req->kStopAtr * $req->atrValue;
        $stopPrice = match ($req->side->value) {
            'long'  => $req->entryPriceBase - $slDistance,
            'short' => $req->entryPriceBase + $slDistance,
        };
        $stopPct = abs($req->entryPriceBase - $stopPrice) / max(1e-9, $req->entryPriceBase);

        $riskUsdt = $req->equityUsdt * ($req->riskPct / 100.0);

        $lev = $this->levCalc->compute(
            riskUsdt:   $riskUsdt,
            stopPct:    $stopPct,
            budgetUsdt: $req->budgetUsdt,
            levMin:     $req->levMin,
            levMax:     $req->levMax,
            kDynamic:   $req->kDynamic
        );

        $qty = ($req->budgetUsdt * $lev) / max(1e-9, $req->entryPriceBase);

        // Quantization très simple: arrondi qty à 1e-6 (tu ajusteras selon le symbole)
        $qty = floor($qty * 1_000_000) / 1_000_000;

        return new RiskDecision(
            stopPct:   $stopPct,
            riskUsdt:  $riskUsdt,
            leverage:  $lev,
            quantity:  $qty
        );
    }
}
