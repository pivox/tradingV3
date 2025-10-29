<?php
declare(strict_types=1);

namespace App\TradeEntry\OrderPlan;

use App\Contract\EntryTrade\LeverageServiceInterface;
use App\TradeEntry\Dto\{TradeEntryRequest, PreflightReport};
use App\TradeEntry\Pricing\TickQuantizer;
use App\TradeEntry\RiskSizer\{PositionSizer, StopLossCalculator, TakeProfitCalculator};
use App\TradeEntry\Types\Side;

final class OrderPlanBuilder
{
    public function __construct(
        private readonly PositionSizer $positionSizer,
        private readonly StopLossCalculator $slc,
        private readonly TakeProfitCalculator $tpc,
        private readonly LeverageServiceInterface $leverageService,
    ) {}

    public function build(TradeEntryRequest $req, PreflightReport $pre): OrderPlanModel
    {
        $precision = $pre->pricePrecision;
        $contractSize = $pre->contractSize;
        $minVolume = $pre->minVolume;

        $availableBudget = min(max($req->initialMarginUsdt, 0.0), max($pre->availableUsdt, 0.0));
        if ($availableBudget <= 0.0) {
            throw new \RuntimeException('Budget indisponible pour construire le plan');
        }

        $riskUsdt = $availableBudget * max($req->riskPct, 0.0);
        if ($riskUsdt <= 0.0) {
            throw new \RuntimeException('Risk USDT nul, ajuster riskPct ou margin');
        }

        $tick = TickQuantizer::tick($precision);

        $entry = $req->side === Side::Long ? $pre->bestBid : $pre->bestAsk;
        if ($req->orderType === 'limit') {
            if ($req->side === Side::Long) {
                $entry = min($entry, $pre->bestAsk - $tick);
                if ($req->entryLimitHint !== null) {
                    $entry = min($entry, $req->entryLimitHint);
                }
            } else {
                $entry = max($entry, $pre->bestBid + $tick);
                if ($req->entryLimitHint !== null) {
                    $entry = max($entry, $req->entryLimitHint);
                }
            }
            $entry = TickQuantizer::quantize($entry, $precision);
        } else {
            $entry = $req->side === Side::Long ? $pre->bestAsk : $pre->bestBid;
        }

        if ($entry <= 0.0) {
            throw new \RuntimeException('Prix d\'entrée invalide');
        }

        $sizingDistance = $tick * 10;
        $stopAtr = null;
        if ($req->stopFrom === 'atr' && $req->atrValue !== null) {
            $stopAtr = $this->slc->fromAtr($entry, $req->side, $req->atrValue, $req->atrK, $precision);
            $sizingDistance = max(abs($entry - $stopAtr), $tick);
        }

        $size = $this->positionSizer->fromRiskAndDistance($riskUsdt, $sizingDistance, $contractSize, $minVolume);

        $stopRisk = $this->slc->fromRisk($entry, $req->side, $riskUsdt, $size, $contractSize, $precision);
        $stop = $stopAtr !== null
            ? $this->slc->conservative($req->side, $stopAtr, $stopRisk)
            : $stopRisk;

        if ($stop <= 0.0 || $stop === $entry) {
            throw new \RuntimeException('Stop loss invalide');
        }

        $takeProfit = $this->tpc->fromRMultiple($entry, $stop, $req->side, $req->rMultiple, $precision);

        $leverage = $this->leverageService->computeLeverage(
            $req->symbol,
            $entry,
            $contractSize,
            $size,
            $req->initialMarginUsdt,
            $pre->availableUsdt,
            $pre->minLeverage,
            $pre->maxLeverage
        );

        $orderMode = $req->orderType === 'market' ? 1 : $req->orderMode;

        return new OrderPlanModel(
            symbol: $req->symbol,
            side: $req->side,
            orderType: $req->orderType,
            openType: $req->openType,
            orderMode: $orderMode,
            entry: $entry,
            stop: $stop,
            takeProfit: $takeProfit,
            size: $size,
            leverage: $leverage,
            pricePrecision: $precision,
            contractSize: $contractSize,
        );
    }
}
