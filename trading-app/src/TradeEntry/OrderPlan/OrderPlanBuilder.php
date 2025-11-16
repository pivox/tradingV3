<?php

declare(strict_types=1);

namespace App\TradeEntry\OrderPlan;

use App\Contract\EntryTrade\LeverageServiceInterface;
use App\Contract\Indicator\IndicatorProviderInterface;
use App\TradeEntry\Dto\{TradeEntryRequest, PreflightReport};
use App\TradeEntry\Pricing\TickQuantizer;
use App\TradeEntry\Dto\EntryZone;
use App\TradeEntry\RiskSizer\{PositionSizer, StopLossCalculator, TakeProfitCalculator};
use App\TradeEntry\Types\Side;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class OrderPlanBuilder
{
    private const MIN_STOP_DISTANCE_PCT = 0.005; // 0.5% minimal absolu

    private static bool $deprecationWarningLogged = false;

    public function __construct(
        private readonly PositionSizer $positionSizer,
        private readonly StopLossCalculator $slc,
        private readonly TakeProfitCalculator $tpc,
        private readonly LeverageServiceInterface $leverageService,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
        private readonly IndicatorProviderInterface $indicatorProvider,
    ) {}

    public function build(
        TradeEntryRequest $req,
        PreflightReport $pre,
        ?string $decisionKey = null,
        ?EntryZone $zone = null
    ): OrderPlanModel {
        // --- Exchange precisions & limits ---
        $precision       = $pre->pricePrecision;
        $tick            = $pre->tickSize ?? TickQuantizer::tick($precision);
        $contractSize    = $pre->contractSize;
        $minVolume       = $pre->minVolume;
        $volPrecision    = $pre->volPrecision ?? 0;
        $sizeStep        = $volPrecision > 0 ? pow(10, -$volPrecision) : 1.0;
        $maxVolume       = $pre->maxVolume;
        $marketMaxVolume = $pre->marketMaxVolume;

        // --- Budget / risk ---
        $availableBudget = min(max((float)$req->initialMarginUsdt, 0.0), max((float)$pre->availableUsdt, 0.0));
        if ($availableBudget <= 0.0) {
            $this->positionsLogger->error('order_plan_builder.no_budget', [
                'symbol' => $req->symbol,
                'decision_key' => $decisionKey,
                'initial_margin_usdt' => $req->initialMarginUsdt,
                'available_usdt' => $pre->availableUsdt,
            ]);
            throw new \RuntimeException('Budget indisponible pour construire le plan');
        }
        $riskPct = $this->normalizePercent($req->riskPct);
        $riskUsdt = $availableBudget * $riskPct;
        if ($riskUsdt <= 0.0) {
            $this->positionsLogger->error('order_plan_builder.invalid_risk', [
                'symbol' => $req->symbol,
                'decision_key' => $decisionKey,
                'risk_pct' => $req->riskPct,
                'available_budget' => $availableBudget,
            ]);
            throw new \RuntimeException('Risk USDT nul, ajuster riskPct ou margin');
        }

        // --- Market snapshot ---
        $bestBid = (float)$pre->bestBid;
        $bestAsk = (float)$pre->bestAsk;
        $mid     = 0.5 * ($bestBid + $bestAsk);
        $mark    = $pre->markPrice ?? $mid;

        // --- Paramètres de garde prix ---
        $insideTicks         = max(1, (int)($req->insideTicks ?? 1));
        $maxDeviationPct     = $this->normalizePercent($req->maxDeviationPct ?? 0.005);  // ~50 bps
        $implausiblePct      = $this->normalizePercent($req->implausiblePct ?? 0.02);   // 2 %
        $zoneMaxDeviationPct = $this->normalizePercent($req->zoneMaxDeviationPct ?? 0.007);

        // ============================================================
        //  ENTRY PRICE (LIMIT / MARKET) - NOUVELLE LOGIQUE
        // ============================================================
        if ($req->orderType === 'limit') {

            $usedZone      = false;
            $ideal         = 0.0;
            $zoneDeviation = 0.0;

            // 1) Prix "maker" de base
            if ($req->side === Side::Long) {
                // On essaye d'être maker juste au-dessus du bid
                $ideal = $bestBid + $tick;
            } else {
                // SHORT: maker juste en-dessous du ask
                $ideal = $bestAsk - $tick;
            }

            // 2) Si EntryZone exploitable et proche du mark, on clamp dedans
            if ($zone instanceof EntryZone) {
                $zoneDeviation = $mark > 0.0
                    ? max(abs($zone->min - $mark), abs($zone->max - $mark)) / $mark
                    : 0.0;

                if ($zoneDeviation <= $zoneMaxDeviationPct) {
                    // Clamp dans la zone
                    if ($req->side === Side::Long) {
                        $ideal = max($zone->min, min($ideal, $zone->max));
                    } else {
                        $ideal = min($zone->max, max($ideal, $zone->min));
                    }
                    $usedZone = true;
                }
            }

            // 3) Hint éventuel (entryLimitHint) : on le respecte sans briser la zone
            if ($req->entryLimitHint !== null) {
                $hint = max((float)$req->entryLimitHint, $tick);
                if ($req->side === Side::Long) {
                    $ideal = min($ideal, $hint);
                } else {
                    $ideal = max($ideal, $hint);
                }
            }

            // 4) Garde maxDeviation vs mark : on rapproche dans un couloir autour du mark
            if ($mark > 0.0) {
                $deviation = abs($ideal - $mark) / $mark;
                if ($deviation > $maxDeviationPct) {
                    $lowGuard  = $mark * (1.0 - $maxDeviationPct);
                    $highGuard = $mark * (1.0 + $maxDeviationPct);

                    // Si une zone est utilisée, on intersecte avec la zone
                    if ($usedZone && $zone instanceof EntryZone) {
                        $lowGuard  = max($lowGuard, $zone->min);
                        $highGuard = min($highGuard, $zone->max);
                    }

                    // Clamp dans le couloir [lowGuard, highGuard]
                    $ideal = max($lowGuard, min($ideal, $highGuard));
                }
            }

            // 5) Si pas de zone (ou zone non utilisable), fallback sur ancienne logique insideTicks
            if (!$usedZone) {
                if ($req->side === Side::Long) {
                    $fallbackIdeal = min($bestAsk - $tick, $bestBid + $insideTicks * $tick);
                    $ideal = max($tick, $fallbackIdeal);
                } else {
                    $fallbackIdeal = max($bestBid + $tick, $bestAsk - $insideTicks * $tick);
                    $ideal = max($tick, $fallbackIdeal);
                }
            }

            // 6) Quantization finale
            if ($req->side === Side::Long) {
                $entry = TickQuantizer::quantize(max($ideal, $tick), $precision);
            } else {
                $entry = TickQuantizer::quantizeUp(max($ideal, $tick), $precision);
            }

            $this->positionsLogger->debug('order_plan.entry_price', [
                'symbol'        => $req->symbol,
                'side'          => $req->side->value,
                'best_bid'      => $bestBid,
                'best_ask'      => $bestAsk,
                'mark'          => $mark,
                'entry'         => $entry,
                'used_zone'     => $usedZone,
                'zone_min'      => $zone?->min,
                'zone_max'      => $zone?->max,
                'zone_deviation'=> $zoneDeviation,
                'max_deviation_pct' => $maxDeviationPct,
                'decision_key'  => $decisionKey,
            ]);

        } else {
            // MARKET: inchangé, on prend le meilleur côté
            $entry = $req->side === Side::Long ? $bestAsk : $bestBid;

            $this->positionsLogger->debug('order_plan.entry_price_market', [
                'symbol'       => $req->symbol,
                'side'         => $req->side->value,
                'best_bid'     => $bestBid,
                'best_ask'     => $bestAsk,
                'mark'         => $mark,
                'entry'        => $entry,
                'decision_key' => $decisionKey,
            ]);
        }

        if (!\is_finite($entry) || $entry <= 0.0) {
            throw new \RuntimeException('Prix d\'entrée invalide');
        }

        // --- Fallback de sécurité si entry délirant vs mark (implausiblePct) ---
        if ($entry <= $tick || (abs($entry - $mark) / max($mark, 1e-8)) > $implausiblePct) {
            $fallback = $req->side === Side::Long
                ? min($bestAsk - $tick, $bestBid + $tick)
                : max($bestBid + $tick, $bestAsk - $tick);

            $entry = $req->side === Side::Long
                ? TickQuantizer::quantize(max($fallback, $tick), $precision)
                : TickQuantizer::quantizeUp(max($fallback, $tick), $precision);

            $this->positionsLogger->warning('order_plan.entry_fallback', [
                'symbol'         => $req->symbol,
                'side'           => $req->side->value,
                'entry'          => $entry,
                'mark'           => $mark,
                'implausible_pct'=> $implausiblePct,
                'decision_key'   => $decisionKey,
            ]);
        }

        // ============================================================
        //  STOPS: ATR / PIVOT / RISK (inchangé)
        // ============================================================
        $sizingDistance = $tick * 10;
        $stopAtr = null;
        $stopPivot = null;

        $hasAtrInputs = $req->atrValue !== null
            && \is_finite($req->atrValue) && $req->atrValue > 0.0
            && \is_finite($req->atrK)     && $req->atrK > 0.0;

        if ($req->stopFrom === 'pivot' && !empty($pre->pivotLevels)) {
            $stopPivot = $this->slc->fromPivot(
                entry: $entry,
                side: $req->side,
                pivotLevels: $pre->pivotLevels,
                policy: $req->pivotSlPolicy ?? 'nearest_below',
                bufferPct: $req->pivotSlBufferPct ?? 0.0015,
                pricePrecision: $precision
            );
            $sizingDistance = max(abs($entry - $stopPivot), $tick);
        }

        if ($req->stopFrom === 'atr') {
            if (!$hasAtrInputs) {
                throw new \InvalidArgumentException('ATR invalide pour stopFrom=atr');
            }
            $stopAtr = $this->slc->fromAtr($entry, $req->side, (float)$req->atrValue, (float)$req->atrK, $precision);
            $sizingDistance = max(abs($entry - $stopAtr), $tick);

            $atrStopDistancePct = abs($entry - $stopAtr) / max($entry, 1e-9);
            if ($atrStopDistancePct < self::MIN_STOP_DISTANCE_PCT) {
                $minAbs = max($tick, self::MIN_STOP_DISTANCE_PCT * $entry);
                $target = $req->side === Side::Long ? max($entry - $minAbs, $tick) : $entry + $minAbs;
                $stopAtr = $req->side === Side::Long
                    ? TickQuantizer::quantize($target, $precision)
                    : TickQuantizer::quantizeUp($target, $precision);
                $sizingDistance = max(abs($entry - $stopAtr), $tick);
            }
        }

        // Garde 0.5% pour le stop pivot
        if ($stopPivot !== null) {
            $pivotStopDistancePct = abs($entry - $stopPivot) / max($entry, 1e-9);
            if ($pivotStopDistancePct < self::MIN_STOP_DISTANCE_PCT) {
                $minAbs = max($tick, self::MIN_STOP_DISTANCE_PCT * $entry);
                $target = $req->side === Side::Long ? max($entry - $minAbs, $tick) : $entry + $minAbs;
                $stopPivot = $req->side === Side::Long
                    ? TickQuantizer::quantize($target, $precision)
                    : TickQuantizer::quantizeUp($target, $precision);
                $sizingDistance = max(abs($entry - $stopPivot), $tick);
            }
        }

        // --- Sizing initial, choix final du stop conservateur ---
        $size = $this->positionSizer->fromRiskAndDistance($riskUsdt, $sizingDistance, $contractSize, $minVolume);

        $stopRisk = $this->slc->fromRisk($entry, $req->side, $riskUsdt, $size, $contractSize, $precision);
        $stop = match (true) {
            $stopPivot !== null => $stopPivot,
            $stopAtr   !== null => $this->slc->conservative($req->side, $stopAtr, $stopRisk),
            default              => $stopRisk,
        };

        // Si le stop final diffère, re-sizer
        $finalDistance = max(abs($entry - $stop), $tick);
        if (abs($finalDistance - $sizingDistance) > 1e-12) {
            $size = $this->positionSizer->fromRiskAndDistance($riskUsdt, $finalDistance, $contractSize, $minVolume);
        }

        // Garde absolue (>= 1 tick) + garde globale 0.5% pour tout SL
        $minTick = TickQuantizer::tick($precision);
        if ($stop <= 0.0 || abs($stop - $entry) < $minTick) {
            $stop = $req->side === Side::Long
                ? TickQuantizer::quantize(max($entry - $minTick, $minTick), $precision)
                : TickQuantizer::quantizeUp($entry + $minTick, $precision);
        }
        if ($stop <= 0.0 || $stop === $entry) {
            throw new \RuntimeException('Stop loss invalide');
        }
        $stopDistancePct = abs($stop - $entry) / max($entry, 1e-9);
        if ($stopDistancePct < self::MIN_STOP_DISTANCE_PCT) {
            $minAbs = max($tick, self::MIN_STOP_DISTANCE_PCT * $entry);
            $target = $req->side === Side::Long ? max($entry - $minAbs, $minTick) : $entry + $minAbs;
            $stop = $req->side === Side::Long
                ? TickQuantizer::quantize($target, $precision)
                : TickQuantizer::quantizeUp($target, $precision);

            $finalDistance = max(abs($entry - $stop), $tick);
            $size = $this->positionSizer->fromRiskAndDistance($riskUsdt, $finalDistance, $contractSize, $minVolume);
            $stopRisk = $this->slc->fromRisk($entry, $req->side, $riskUsdt, (int)$size, $contractSize, $precision);

            $this->positionsLogger->info('order_plan.stop_min_distance_adjusted', [
                'symbol' => $req->symbol,
                'side' => $req->side->value,
                'entry' => $entry,
                'stop_before' => $stopRisk,
                'stop_after' => $stop,
                'min_distance_pct' => self::MIN_STOP_DISTANCE_PCT,
                'decision_key' => $decisionKey,
            ]);
        }

        // --- Quantisation / clamps taille ---
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
            throw new \RuntimeException('Taille calculée invalide (<=0)');
        }

        $this->positionsLogger->debug('order_plan.sizing', [
            'symbol' => $req->symbol,
            'risk_usdt' => $riskUsdt,
            'final_distance' => $finalDistance,
            'size' => $sizeContracts,
            'decision_key' => $decisionKey,
        ]);

        // --- TAKE PROFIT : R-multiple puis « snap » sur pivot avec garde en R ---
        $tpTheoretical = $this->tpc->fromRMultiple($entry, $stop, $req->side, (float)$req->rMultiple, $precision);

        $pivotLevels = \is_array($pre->pivotLevels) && !empty($pre->pivotLevels) ? $pre->pivotLevels : null;
        if ($pivotLevels === null) {
            $list15 = $this->indicatorProvider->getListPivot(key: 'pivot', symbol: $req->symbol, tf: '15m');
            if ($list15 !== null) {
                $indicators = $list15->indicators;
                if (\is_array($indicators['pivot_levels'] ?? null) && !empty($indicators['pivot_levels'])) {
                    $pivotLevels = $indicators['pivot_levels'];
                }
            }
        }

        $takeProfit = $tpTheoretical;
        $pickedFromPivot = false;
        if (\is_array($pivotLevels) && !empty($pivotLevels) && $req->rMultiple > 0.0) {
            $takeProfit = $this->tpc->alignTakeProfitWithPivot(
                symbol: $req->symbol,
                side: $req->side,
                entry: $entry,
                stop: $stop,
                baseTakeProfit: $tpTheoretical,
                rMultiple: (float)$req->rMultiple,
                pivotLevels: $pivotLevels,
                policy: $req->tpPolicy,
                bufferPct: $req->tpBufferPct,
                bufferTicks: $req->tpBufferTicks,
                tick: $tick,
                pricePrecision: $precision,
                minKeepRatio: $req->tpMinKeepRatio,
                maxExtraR: $req->tpMaxExtraR,
                decisionKey: $decisionKey,
            );
            $pickedFromPivot = true;
        }

        $riskUnit = abs($entry - $stop);
        $rTheoretical = (float)($req->rMultiple ?? 0.0);
        $rEffective = $riskUnit > 0.0
            ? (($req->side === Side::Long ? ($takeProfit - $entry) : ($entry - $takeProfit)) / $riskUnit)
            : 0.0;

        $this->positionsLogger->debug('order_plan.take_profit_selected', [
            'symbol' => $req->symbol,
            'side' => $req->side->value,
            'entry' => $entry,
            'stop' => $stop,
            'tp_theoretical' => $tpTheoretical,
            'tp_final' => $takeProfit,
            'take_profit' => $takeProfit,
            'r_theoretical' => $rTheoretical,
            'r_effective' => round($rEffective, 3),
            'aligned_on_pivot' => $pickedFromPivot,
            'decision_key' => $decisionKey,
        ]);

        // --- Levier dynamique ---
        $stopPct = abs($stop - $entry) / max($entry, 1e-9);

        $atr15m = $this->indicatorProvider->getAtr(symbol: $req->symbol, tf: '15m');
        $leverage = $this->leverageService->computeLeverage(
            $req->symbol,
            $entry,
            $contractSize,
            $sizeContracts,
            $req->initialMarginUsdt,
            $pre->availableUsdt,
            $pre->minLeverage,
            $pre->maxLeverage,
            $stopPct,
            $atr15m,
            $req->executionTf,
        );

        $this->positionsLogger->debug('order_plan.leverage.dynamic', [
            'symbol' => $req->symbol,
            'stop_pct' => $stopPct,
            'atr_15m' => $atr15m,
            'leverage' => $leverage,
            'decision_key' => $decisionKey,
        ]);

        // --- Budget check (marge) et ajustements éventuels de taille ---
        $notional = $entry * $contractSize * $sizeContracts;
        $initialMargin = $notional / max(1.0, (float)$leverage);
        if ($initialMargin > $availableBudget) {
            $requiredLev = max(1.0, $notional / max($availableBudget, 1e-8));
            $adjLev = min(max($requiredLev, (float)$pre->minLeverage), (float)$pre->maxLeverage);
            if ($adjLev > (float)$leverage) {
                $leverage = (int)ceil($adjLev);
                $initialMargin = $notional / $leverage;
            }
            if ($initialMargin > $availableBudget) {
                $maxNotional = $availableBudget * $leverage;
                $size = max(
                    $minVolume,
                    floor((($maxNotional) / max($entry * $contractSize, 1e-8)) / $sizeStep) * $sizeStep
                );
                if ($maxVolume !== null && $maxVolume > 0.0) { $size = min($size, $maxVolume); }
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

        $this->positionsLogger->debug('order_plan.budget_check', [
            'symbol' => $req->symbol,
            'risk_usdt' => $riskUsdt,
            'entry' => $entry,
            'size' => $sizeContracts,
            'contract_size' => $contractSize,
            'notional_usdt' => $notional,
            'initial_margin_usdt' => $initialMargin,
            'available_usdt' => $pre->availableUsdt,
            'leverage' => $leverage,
            'decision_key' => $decisionKey,
        ]);

        // --- Build du modèle final ---
        $orderMode = $req->orderType === 'market' ? 1 : $req->orderMode;

        $zoneExpiresAt = null;
        if ($zone instanceof EntryZone && $zone->getTtlSec() !== null && $zone->getCreatedAt() !== null) {
            try {
                $zoneExpiresAt = $zone->getCreatedAt()->modify(sprintf('+%d seconds', (int)$zone->getTtlSec()));
            } catch (\Throwable) {
                $zoneExpiresAt = null;
            }
        }

        // Calcul de la déviation actuelle entre prix d'entrée et mark price
        $deviationPct = $mark > 0.0 ? abs($entry - $mark) / $mark : 0.0;

        $this->positionsLogger->info('order_plan.deviation_check', [
            'symbol' => $req->symbol,
            'side' => $req->side instanceof \App\TradeEntry\Types\Side ? $req->side->value : (string)$req->side,
            'timeframe' => $req->executionTf ?? null,
            'entry_price' => $entry,
            'mark_price' => $mark,
            'deviation_pct' => $deviationPct,
            'zone_max_deviation_pct' => $zoneMaxDeviationPct,
            'implausible_pct' => $implausiblePct,
            'max_deviation_pct' => $maxDeviationPct,
            'decision_key' => $decisionKey,
        ]);

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
            leverage: (int)$leverage,
            pricePrecision: $precision,
            contractSize: $contractSize,
            entryZoneLow: $zone?->min,
            entryZoneHigh: $zone?->max,
            zoneExpiresAt: $zoneExpiresAt,
            entryZoneMeta: $zone?->getMetadata(),
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

        return $model;
    }

    private function normalizePercent(float $value): float
    {
        $value = max(0.0, $value);
        if ($value > 1.0) { $value *= 0.01; }
        return min($value, 1.0);
    }

    // ====== Helpers dépréciés (compatibilité) ======

    /**
     * @deprecated Utilisé uniquement pour compatibilité. Le nouveau flux linéaire calcule directement.
     */
    private function pickProvisionalDistance(float $entry, float $tick, ?float $stopAtr, ?float $stopPivot): float
    {
        if (!self::$deprecationWarningLogged) {
            $this->positionsLogger->warning('order_plan.helper_deprecated', [
                'helper' => 'pickProvisionalDistance',
                'message' => 'Helper déprécié, le nouveau flux linéaire calcule directement',
            ]);
            self::$deprecationWarningLogged = true;
        }

        $cands = [];
        if ($stopAtr   !== null) { $cands[] = abs($entry - $stopAtr); }
        if ($stopPivot !== null) { $cands[] = abs($entry - $stopPivot); }
        $cands[] = $tick * 10;
        return max($tick, max($cands));
    }

    /**
     * @deprecated Utilisé uniquement pour compatibilité.
     */
    private function quantizeContracts(
        float $sizeFloat,
        float $sizeStep,
        float $minVolume,
        ?float $maxVolume,
        ?float $marketMaxVolume
    ): int {
        if (!self::$deprecationWarningLogged) {
            $this->positionsLogger->warning('order_plan.helper_deprecated', [
                'helper' => 'quantizeContracts',
                'message' => 'Helper déprécié, le nouveau flux linéaire quantifie directement',
            ]);
            self::$deprecationWarningLogged = true;
        }

        $s = floor($sizeFloat / $sizeStep) * $sizeStep;
        $s = max($s, $minVolume);
        if ($maxVolume !== null && $maxVolume > 0.0) {
            $s = min($s, $maxVolume);
        }
        if ($marketMaxVolume !== null && $marketMaxVolume > 0.0) {
            $s = min($s, $marketMaxVolume);
        }
        return (int)max($minVolume, floor($s));
    }

    /**
     * @deprecated Utilisé uniquement pour compatibilité.
     */
    private function enforceMinDistanceAndQuantize(
        float $entry,
        float $stop,
        Side $side,
        int $precision,
        float $tick,
        float $minPct
    ): float {
        if (!self::$deprecationWarningLogged) {
            $this->positionsLogger->warning('order_plan.helper_deprecated', [
                'helper' => 'enforceMinDistanceAndQuantize',
                'message' => 'Helper déprécié, le nouveau flux linéaire applique la garde directement',
            ]);
            self::$deprecationWarningLogged = true;
        }

        $minAbs = max($tick, $minPct * $entry);
        $want   = ($side === Side::Long)
            ? max($entry - $minAbs, $tick)
            : $entry + $minAbs;

        if ($side === Side::Long) {
            $stop = min($stop, $want);
            $stop = TickQuantizer::quantize(max($stop, $tick), $precision);
        } else {
            $stop = max($stop, $want);
            $stop = TickQuantizer::quantizeUp($stop, $precision);
        }
        return $stop;
    }
}
