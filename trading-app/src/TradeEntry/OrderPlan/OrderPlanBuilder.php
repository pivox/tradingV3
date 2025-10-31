<?php
declare(strict_types=1);

namespace App\TradeEntry\OrderPlan;

use App\Contract\EntryTrade\LeverageServiceInterface;
use App\TradeEntry\Dto\{TradeEntryRequest, PreflightReport};
use App\TradeEntry\Pricing\TickQuantizer;
use App\TradeEntry\Dto\EntryZone;
use App\TradeEntry\RiskSizer\{PositionSizer, StopLossCalculator, TakeProfitCalculator};
use App\TradeEntry\Types\Side;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class OrderPlanBuilder
{
    public function __construct(
        private readonly PositionSizer $positionSizer,
        private readonly StopLossCalculator $slc,
        private readonly TakeProfitCalculator $tpc,
        private readonly LeverageServiceInterface $leverageService,
        #[Autowire(service: 'monolog.logger.positions_flow')] private readonly LoggerInterface $flowLogger,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {}

    public function build(TradeEntryRequest $req, PreflightReport $pre, ?string $decisionKey = null, ?EntryZone $zone = null): OrderPlanModel
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

        // If a zone is provided, clamp entry to zone to avoid out-of-zone errors later.
        if ($zone instanceof EntryZone) {
            $clamped = max($zone->min, min($entry, $zone->max));
            if ($clamped !== $entry) {
                $adj = $req->side === Side::Long
                    ? TickQuantizer::quantize($clamped, $precision)
                    : TickQuantizer::quantizeUp($clamped, $precision);
                $this->flowLogger->info('order_plan.entry_clamped_to_zone', [
                    'symbol' => $req->symbol,
                    'side' => $req->side->value,
                    'entry_before' => $entry,
                    'entry_after' => $adj,
                    'zone_min' => $zone->min,
                    'zone_max' => $zone->max,
                    'decision_key' => $decisionKey,
                ]);
                $entry = $adj;
            }
        }

        // Extra guard: if entry looks implausible (e.g., 1.0), fallback to best bid/ask
        // Rationale: prevent downstream zone checks from failing due to bad pricing hints or precision issues.
        $mid = 0.5 * ($pre->bestBid + $pre->bestAsk);
        if (!\is_finite($entry) || $entry <= $tick || $entry < 0.2 * $mid || $entry > 5.0 * $mid) {
            $fallback = $req->side === Side::Long ? max($pre->bestBid, $pre->bestAsk - $tick) : min($pre->bestAsk, $pre->bestBid + $tick);
            $fixed = $req->side === Side::Long
                ? TickQuantizer::quantize($fallback, $precision)
                : TickQuantizer::quantizeUp($fallback, $precision);
            $this->flowLogger->warning('order_plan.entry_price_fallback', [
                'symbol' => $req->symbol,
                'side' => $req->side->value,
                'entry_before' => $entry,
                'best_bid' => $pre->bestBid,
                'best_ask' => $pre->bestAsk,
                'mid' => $mid,
                'tick' => $tick,
                'entry_after' => $fixed,
                'decision_key' => $decisionKey,
            ]);
            $entry = $fixed;
        }

        $this->flowLogger->debug('order_plan.entry_price_selected', [
            'symbol' => $req->symbol,
            'side' => $req->side->value,
            'order_type' => $req->orderType,
            'best_bid' => $pre->bestBid,
            'best_ask' => $pre->bestAsk,
            'tick' => $tick,
            'entry_limit_hint' => $req->entryLimitHint,
            'entry' => $entry,
            'decision_key' => $decisionKey,
        ]);

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

        $this->flowLogger->debug('order_plan.sizing', [
            'symbol' => $req->symbol,
            'risk_usdt' => $riskUsdt,
            'sizing_distance' => $sizingDistance,
            'contract_size' => $contractSize,
            'min_volume' => $minVolume,
            'size' => $size,
            'decision_key' => $decisionKey,
        ]);

        // Assurer un écart minimal d'un tick entre entry et stop après quantification
        $minTick = TickQuantizer::tick($precision);
        if ($stop <= 0.0 || abs($stop - $entry) < $minTick) {
            if ($req->side === Side::Long) {
                $stop = TickQuantizer::quantize(max($entry - $minTick, $minTick), $precision);
            } else {
                $stop = TickQuantizer::quantizeUp($entry + $minTick, $precision);
            }
        }
        if ($stop <= 0.0 || $stop === $entry) {
            throw new \RuntimeException('Stop loss invalide');
        }

        $takeProfit = $this->tpc->fromRMultiple($entry, $stop, $req->side, $req->rMultiple, $precision);

        $this->flowLogger->debug('order_plan.stop_and_tp', [
            'symbol' => $req->symbol,
            'entry' => $entry,
            'stop_from' => $req->stopFrom,
            'atr_value' => $req->atrValue,
            'atr_k' => $req->atrK,
            'stop_atr' => $stopAtr,
            'stop_risk' => $stopRisk,
            'stop' => $stop,
            'tp' => $takeProfit,
            'r_multiple' => $req->rMultiple,
            'decision_key' => $decisionKey,
        ]);

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

        $model = new OrderPlanModel(
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

        $this->positionsLogger->info('order_plan.model_ready', [
            'symbol' => $model->symbol,
            'side' => $model->side->value,
            'order_type' => $model->orderType,
            'open_type' => $model->openType,
            'order_mode' => $model->orderMode,
            'entry' => $model->entry,
            'stop' => $model->stop,
            'take_profit' => $model->takeProfit,
            'size' => $model->size,
            'leverage' => $model->leverage,
            'decision_key' => $decisionKey,
        ]);

        return $model;
    }
}
