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
        #[Autowire(service: 'monolog.logger.order_journey')] private readonly LoggerInterface $journeyLogger,
    ) {}

    public function build(TradeEntryRequest $req, PreflightReport $pre, ?string $decisionKey = null, ?EntryZone $zone = null): OrderPlanModel
    {
        $precision       = $pre->pricePrecision;
        $contractSize    = $pre->contractSize;
        $minVolume       = $pre->minVolume;
        $volPrecision    = $pre->volPrecision ?? 0;
        $maxVolume       = $pre->maxVolume;
        $marketMaxVolume = $pre->marketMaxVolume;

        $availableBudget = min(max((float)$req->initialMarginUsdt, 0.0), max((float)$pre->availableUsdt, 0.0));
        if ($availableBudget <= 0.0) {
            $this->journeyLogger->error('order_journey.plan_builder.no_budget', [
                'symbol' => $req->symbol,
                'decision_key' => $decisionKey,
                'initial_margin_usdt' => $req->initialMarginUsdt,
                'available_usdt' => $pre->availableUsdt,
                'reason' => 'insufficient_margin',
            ]);
            throw new \RuntimeException('Budget indisponible pour construire le plan');
        }

        $riskPct = $this->normalizePercent($req->riskPct);
        $riskUsdt = $availableBudget * $riskPct;
        if ($riskUsdt <= 0.0) {
            $this->journeyLogger->error('order_journey.plan_builder.invalid_risk', [
                'symbol' => $req->symbol,
                'decision_key' => $decisionKey,
                'risk_pct' => $req->riskPct,
                'available_budget' => $availableBudget,
                'reason' => 'computed_risk_zero',
            ]);
            throw new \RuntimeException('Risk USDT nul, ajuster riskPct ou margin');
        }

        $tick = $pre->tickSize ?? TickQuantizer::tick($precision);
        $sizeStep = $volPrecision > 0 ? pow(10, -$volPrecision) : 1.0;

        $bestBid = (float)$pre->bestBid;
        $bestAsk = (float)$pre->bestAsk;
        $mid     = 0.5 * ($bestBid + $bestAsk);
        $mark    = $pre->markPrice ?? $mid;

        $insideTicks         = max(1, (int)($req->insideTicks ?? 1));
        $maxDeviationPct     = $this->normalizePercent($req->maxDeviationPct ?? 0.005);
        $implausiblePct      = $this->normalizePercent($req->implausiblePct ?? 0.02);
        $zoneMaxDeviationPct = $this->normalizePercent($req->zoneMaxDeviationPct ?? 0.007);

        if ($req->orderType === 'limit') {
            if ($req->side === Side::Long) {
                $entry = min($bestAsk - $tick, $bestBid + $insideTicks * $tick);
                if ($req->entryLimitHint !== null) {
                    $hint = max((float)$req->entryLimitHint, $tick);
                    $hint = TickQuantizer::quantize($hint, $precision);
                    $entry = max($entry, $bestBid - 2 * $tick);
                    $entry = min($entry, $hint);
                }
                $dev = abs($entry - $mark) / max($mark, 1e-8);
                if ($dev > $maxDeviationPct) {
                    $entry = min($bestAsk - $tick, $bestBid + $tick);
                }
                $entry = max($entry, $tick);
                $entry = TickQuantizer::quantize($entry, $precision);
            } else {
                $entry = max($bestBid + $tick, $bestAsk - $insideTicks * $tick);
                if ($req->entryLimitHint !== null) {
                    $hint = max((float)$req->entryLimitHint, $tick);
                    $hint = TickQuantizer::quantizeUp($hint, $precision);
                    $entry = min($entry, $bestAsk + 2 * $tick);
                    $entry = max($entry, $hint);
                }
                $dev = abs($entry - $mark) / max($mark, 1e-8);
                if ($dev > $maxDeviationPct) {
                    $entry = max($bestBid + $tick, $bestAsk - $tick);
                }
                $entry = max($entry, $tick);
                $entry = TickQuantizer::quantizeUp($entry, $precision);
            }
        } else {
            $entry = $req->side === Side::Long ? $bestAsk : $bestBid;
        }

        $this->flowLogger->debug('order_plan.entry_price_baseline', [
            'symbol' => $req->symbol,
            'side' => $req->side->value,
            'best_bid' => $bestBid,
            'best_ask' => $bestAsk,
            'mark_price' => $mark,
            'entry' => $entry,
            'inside_ticks' => $insideTicks,
            'max_dev_pct' => $maxDeviationPct,
            'decision_key' => $decisionKey,
        ]);
        $this->journeyLogger->debug('order_journey.plan_builder.entry_selected', [
            'symbol' => $req->symbol,
            'decision_key' => $decisionKey,
            'entry' => $entry,
            'side' => $req->side->value,
            'order_type' => $req->orderType,
            'reason' => 'entry_price_finalized',
        ]);

        if (!\is_finite($entry) || $entry <= 0.0) {
            $this->journeyLogger->error('order_journey.plan_builder.invalid_entry', [
                'symbol' => $req->symbol,
                'decision_key' => $decisionKey,
                'entry' => $entry,
                'reason' => 'entry_non_positive',
            ]);
            throw new \RuntimeException('Prix d\'entrée invalide');
        }

        if ($zone instanceof EntryZone) {
            $zoneDeviation = $mark > 0.0 ? max(abs($zone->min - $mark), abs($zone->max - $mark)) / $mark : 0.0;
            if ($zoneDeviation <= $zoneMaxDeviationPct) {
                $clamped = max($zone->min, min($entry, $zone->max));
                $adj = $req->side === Side::Long
                    ? TickQuantizer::quantize($clamped, $precision)
                    : TickQuantizer::quantizeUp($clamped, $precision);
                if ($adj !== $entry) {
                    $this->flowLogger->info('order_plan.entry_clamped_to_zone', [
                        'symbol' => $req->symbol,
                        'entry_before' => $entry,
                        'entry_after' => $adj,
                        'zone_min' => $zone->min,
                        'zone_max' => $zone->max,
                        'decision_key' => $decisionKey,
                    ]);
                    $this->journeyLogger->info('order_journey.plan_builder.entry_clamped', [
                        'symbol' => $req->symbol,
                        'decision_key' => $decisionKey,
                        'entry_before' => $entry,
                        'entry_after' => $adj,
                        'zone_min' => $zone->min,
                        'zone_max' => $zone->max,
                        'reason' => 'entry_adjusted_to_zone',
                    ]);
                    $entry = $adj;
                }
            } else {
                $this->flowLogger->warning('order_plan.zone_ignored_far_from_market', [
                    'symbol' => $req->symbol,
                    'zone_min' => $zone->min,
                    'zone_max' => $zone->max,
                    'mark' => $mark,
                    'zone_dev_pct' => $zoneDeviation,
                    'decision_key' => $decisionKey,
                ]);
                $this->journeyLogger->info('order_journey.plan_builder.zone_ignored', [
                    'symbol' => $req->symbol,
                    'decision_key' => $decisionKey,
                    'zone_dev_pct' => $zoneDeviation,
                    'reason' => 'zone_far_from_market',
                ]);
            }
        }

        if ($entry <= $tick || (abs($entry - $mark) / max($mark, 1e-8)) > $implausiblePct) {
            $fallback = $req->side === Side::Long
                ? min($bestAsk - $tick, $bestBid + $tick)
                : max($bestBid + $tick, $bestAsk - $tick);
            $entry = $req->side === Side::Long
                ? TickQuantizer::quantize($fallback, $precision)
                : TickQuantizer::quantizeUp($fallback, $precision);

            $this->flowLogger->warning('order_plan.entry_price_fallback', [
                'symbol' => $req->symbol,
                'entry_after' => $entry,
                'mark' => $mark,
                'implausible_pct' => $implausiblePct,
                'decision_key' => $decisionKey,
            ]);
            $this->journeyLogger->warning('order_journey.plan_builder.entry_fallback', [
                'symbol' => $req->symbol,
                'decision_key' => $decisionKey,
                'entry_after' => $entry,
                'reason' => 'entry_too_far_from_mark',
            ]);
        }

        $sizingDistance = $tick * 10;
        $stopAtr = null;

        if ($req->stopFrom === 'atr') {
            if (!\is_finite($req->atrValue) || $req->atrValue <= 0.0 || $req->atrK <= 0.0) {
                throw new \InvalidArgumentException('ATR invalide pour stopFrom=atr');
            }
            $stopAtr = $this->slc->fromAtr($entry, $req->side, (float)$req->atrValue, (float)$req->atrK, $precision);
            $sizingDistance = max(abs($entry - $stopAtr), $tick);
        }

        $size = $this->positionSizer->fromRiskAndDistance($riskUsdt, $sizingDistance, $contractSize, $minVolume);

        $stopRisk = $this->slc->fromRisk($entry, $req->side, $riskUsdt, $size, $contractSize, $precision);
        $stop = $stopAtr !== null
            ? $this->slc->conservative($req->side, $stopAtr, $stopRisk)
            : $stopRisk;

        $finalDistance = max(abs($entry - $stop), $tick);
        if (abs($finalDistance - $sizingDistance) > 1e-12) {
            $size = $this->positionSizer->fromRiskAndDistance($riskUsdt, $finalDistance, $contractSize, $minVolume);
        }

        $this->flowLogger->debug('order_plan.sizing', [
            'symbol' => $req->symbol,
            'risk_usdt' => $riskUsdt,
            'initial_distance' => $sizingDistance,
            'final_distance' => $finalDistance,
            'contract_size' => $contractSize,
            'min_volume' => $minVolume,
            'size_prequant' => $size,
            'decision_key' => $decisionKey,
        ]);
        $this->journeyLogger->debug('order_journey.plan_builder.sizing', [
            'symbol' => $req->symbol,
            'decision_key' => $decisionKey,
            'risk_usdt' => $riskUsdt,
            'size_prequant' => $size,
            'reason' => 'position_size_computed',
        ]);

        if ($volPrecision === 0) {
            $size = (float)floor($size);
        } else {
            $size = floor($size / $sizeStep) * $sizeStep;
        }
        $size = max($size, (float)$minVolume);
        if ($req->orderType === 'market' && $marketMaxVolume !== null && $marketMaxVolume > 0.0) {
            $size = min($size, $marketMaxVolume);
        } elseif ($maxVolume !== null && $maxVolume > 0.0) {
            $size = min($size, $maxVolume);
        }

        $sizeContracts = (int)max($minVolume, floor($size));
        if ($sizeContracts <= 0) {
            throw new \RuntimeException('Taille calculée invalide');
        }

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

        $takeProfit = $this->tpc->fromRMultiple($entry, $stop, $req->side, (float)$req->rMultiple, $precision);

        if (is_array($pre->pivotLevels) && !empty($pre->pivotLevels) && $req->rMultiple > 0.0) {
            $takeProfit = $this->alignTakeProfitWithPivot(
                symbol: $req->symbol,
                side: $req->side,
                entry: $entry,
                stop: $stop,
                baseTakeProfit: $takeProfit,
                rMultiple: (float)$req->rMultiple,
                pivotLevels: $pre->pivotLevels,
                policy: $req->tpPolicy,
                bufferPct: $req->tpBufferPct,
                bufferTicks: $req->tpBufferTicks,
                tick: $tick,
                pricePrecision: $precision,
                minKeepRatio: $req->tpMinKeepRatio,
                maxExtraR: $req->tpMaxExtraR,
                decisionKey: $decisionKey,
            );
        }

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
        $this->journeyLogger->debug('order_journey.plan_builder.stop_tp', [
            'symbol' => $req->symbol,
            'decision_key' => $decisionKey,
            'entry' => $entry,
            'stop' => $stop,
            'take_profit' => $takeProfit,
            'reason' => 'risk_targets_calculated',
        ]);

        $leverage = $this->leverageService->computeLeverage(
            $req->symbol,
            $entry,
            $contractSize,
            $sizeContracts,
            $req->initialMarginUsdt,
            $pre->availableUsdt,
            $pre->minLeverage,
            $pre->maxLeverage
        );

        $notional = $entry * $contractSize * $sizeContracts;
        $initialMargin = $notional / max(1.0, (float)$leverage);
        if ($initialMargin > $availableBudget) {
            $requiredLeverage = max(1.0, $notional / max($availableBudget, 1e-8));
            $adjustedLeverage = min(max($requiredLeverage, (float)$pre->minLeverage), (float)$pre->maxLeverage);
            if ($adjustedLeverage > (float)$leverage) {
                $leverage = $adjustedLeverage;
                $initialMargin = $notional / $leverage;
            }
            if ($initialMargin > $availableBudget) {
                $maxNotional = $availableBudget * $leverage;
                $size = max($minVolume, floor(($maxNotional / max($entry * $contractSize, 1e-8)) / $sizeStep) * $sizeStep);
                if ($maxVolume !== null && $maxVolume > 0.0) {
                    $size = min($size, $maxVolume);
                }
                if ($req->orderType === 'market' && $marketMaxVolume !== null && $marketMaxVolume > 0.0) {
                    $size = min($size, $marketMaxVolume);
                }
                $sizeContracts = (int)max($minVolume, floor($size));
                if ($sizeContracts <= 0) {
                    throw new \RuntimeException('Taille ajustée invalide après contrôle budget');
                }
                $notional = $entry * $contractSize * $sizeContracts;
                $initialMargin = $notional / $leverage;
            }
        }

        $this->flowLogger->debug('order_plan.budget_check', [
            'symbol' => $req->symbol,
            'risk_usdt' => $riskUsdt,
            'entry' => $entry,
            'size' => $sizeContracts,
            'contract_size' => $contractSize,
            'notional_usdt' => $notional,
            'initial_margin_budget' => $req->initialMarginUsdt,
            'initial_margin_usdt' => $initialMargin,
            'available_usdt' => $pre->availableUsdt,
            'leverage' => $leverage,
            'decision_key' => $decisionKey,
        ]);
        $this->journeyLogger->debug('order_journey.plan_builder.leverage', [
            'symbol' => $req->symbol,
            'decision_key' => $decisionKey,
            'leverage' => $leverage,
            'notional_usdt' => $notional,
            'initial_margin_usdt' => $initialMargin,
            'reason' => 'leverage_and_margin_computed',
        ]);

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
            size: $sizeContracts,
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
            'contract_size' => $model->contractSize,
            'notional_usdt' => $model->entry * $model->contractSize * $model->size,
            'initial_margin_usdt' => ($model->entry * $model->contractSize * $model->size) / max(1.0, (float)$model->leverage),
            'decision_key' => $decisionKey,
        ]);
        $this->journeyLogger->info('order_journey.plan_builder.model_ready', [
            'symbol' => $model->symbol,
            'decision_key' => $decisionKey,
            'entry' => $model->entry,
            'stop' => $model->stop,
            'take_profit' => $model->takeProfit,
            'size' => $model->size,
            'leverage' => $model->leverage,
            'order_mode' => $model->orderMode,
            'reason' => 'order_plan_ready_for_execution',
        ]);

        return $model;
    }

    private function normalizePercent(float $value): float
    {
        $value = max(0.0, $value);
        if ($value > 1.0) {
            $value *= 0.01;
        }

        return min($value, 1.0);
    }

    private function alignTakeProfitWithPivot(
        string $symbol,
        Side $side,
        float $entry,
        float $stop,
        float $baseTakeProfit,
        float $rMultiple,
        array $pivotLevels,
        string $policy,
        ?float $bufferPct,
        ?int $bufferTicks,
        float $tick,
        int $pricePrecision,
        float $minKeepRatio,
        ?float $maxExtraR,
        ?string $decisionKey
    ): float {
        $riskUnit = $side === Side::Long ? $entry - $stop : $stop - $entry;
        if ($riskUnit <= 0.0) {
            return $baseTakeProfit;
        }

        $tpTheoretical = $side === Side::Long
            ? $entry + $rMultiple * $riskUnit
            : $entry - $rMultiple * $riskUnit;

        $pivots = $this->collectPivotsForSide($side, $entry, $pivotLevels);
        $candidate = $this->choosePivotCandidate($pivots, $side, $tpTheoretical, $entry, $riskUnit, $rMultiple, $policy, $maxExtraR);

        $tpRaw = $candidate ?? $tpTheoretical;
        $tpRaw = $this->applyTpBuffer($side, $tpRaw, $bufferPct, $bufferTicks, $tick);

        if ($side === Side::Long) {
            $tpRaw = max($tpRaw, $entry + $tick);
            $tpFinal = TickQuantizer::quantizeUp($tpRaw, $pricePrecision);
            if ($tpFinal <= $entry) {
                $tpFinal = TickQuantizer::quantizeUp($entry + $tick, $pricePrecision);
            }
        } else {
            $tpRaw = min($tpRaw, $entry - $tick);
            $tpFinal = TickQuantizer::quantize($tpRaw, $pricePrecision);
            if ($tpFinal >= $entry) {
                $tpFinal = TickQuantizer::quantize($entry - $tick, $pricePrecision);
            }
        }

        $effectiveK = abs($tpFinal - $entry) / $riskUnit;
        if ($effectiveK < $minKeepRatio * $rMultiple) {
            $tpFinal = $side === Side::Long
                ? TickQuantizer::quantizeUp(max($tpTheoretical, $entry + $tick), $pricePrecision)
                : TickQuantizer::quantize(min($tpTheoretical, $entry - $tick), $pricePrecision);
        }

        $this->flowLogger->info('order_plan.tp_aligned', [
            'symbol' => $symbol,
            'side' => $side->value,
            'entry' => $entry,
            'stop' => $stop,
            'risk_unit' => $riskUnit,
            'tp_theoretical' => $tpTheoretical,
            'tp_candidate' => $candidate,
            'tp_final' => $tpFinal,
            'policy' => $policy,
            'buffer_pct' => $bufferPct,
            'buffer_ticks' => $bufferTicks,
            'effective_k' => $effectiveK,
            'decision_key' => $decisionKey,
        ]);

        $this->journeyLogger->info('order_journey.plan_builder.tp_aligned', [
            'symbol' => $symbol,
            'decision_key' => $decisionKey,
            'policy' => $policy,
            'tp_theoretical' => $tpTheoretical,
            'tp_candidate' => $candidate,
            'tp_final' => $tpFinal,
            'buffer_pct' => $bufferPct,
            'buffer_ticks' => $bufferTicks,
            'effective_k' => $effectiveK,
            'min_keep_ratio' => $minKeepRatio,
        ]);

        return $tpFinal;
    }

    /**
     * @return float[]
     */
    private function collectPivotsForSide(Side $side, float $entry, array $pivotLevels): array
    {
        $levels = [];
        $pp = $pivotLevels['pp'] ?? null;

        if ($side === Side::Long) {
            if ($pp !== null && $entry < $pp) {
                $levels[] = $pp;
            }
            foreach (['r1', 'r2', 'r3'] as $key) {
                if (isset($pivotLevels[$key])) {
                    $levels[] = $pivotLevels[$key];
                }
            }
            $levels = array_values(array_filter($levels, static fn($v) => is_finite((float)$v)));
            sort($levels, SORT_NUMERIC);
        } else {
            if ($pp !== null && $entry > $pp) {
                $levels[] = $pp;
            }
            foreach (['s1', 's2', 's3'] as $key) {
                if (isset($pivotLevels[$key])) {
                    $levels[] = $pivotLevels[$key];
                }
            }
            $levels = array_values(array_filter($levels, static fn($v) => is_finite((float)$v)));
            rsort($levels, SORT_NUMERIC);
        }

        return $levels;
    }

    private function choosePivotCandidate(
        array $pivots,
        Side $side,
        float $tpTheoretical,
        float $entry,
        float $riskUnit,
        float $rMultiple,
        string $policy,
        ?float $maxExtraR
    ): ?float {
        if (empty($pivots)) {
            return null;
        }

        $candidate = null;
        if ($side === Side::Long) {
            foreach ($pivots as $pivot) {
                if ($pivot >= $tpTheoretical) {
                    $candidate = $pivot;
                    break;
                }
            }
        } else {
            foreach ($pivots as $pivot) {
                if ($pivot <= $tpTheoretical) {
                    $candidate = $pivot;
                    break;
                }
            }
        }

        if ($candidate === null) {
            return null;
        }

        if ($policy === 'pivot_aggressive' && $maxExtraR !== null) {
            $candidateKR = abs($candidate - $entry) / $riskUnit;
            if ($candidateKR > $rMultiple + $maxExtraR) {
                return null;
            }
        }

        return $candidate;
    }

    private function applyTpBuffer(Side $side, float $tp, ?float $bufferPct, ?int $bufferTicks, float $tick): float
    {
        $result = $tp;

        if ($bufferPct !== null && $bufferPct > 0.0) {
            $factor = $bufferPct;
            $result = $side === Side::Long
                ? $result * (1.0 - $factor)
                : $result * (1.0 + $factor);
        }

        if ($bufferTicks !== null && $bufferTicks > 0) {
            $offset = $bufferTicks * $tick;
            $result = $side === Side::Long ? $result - $offset : $result + $offset;
        }

        return $result;
    }
}
