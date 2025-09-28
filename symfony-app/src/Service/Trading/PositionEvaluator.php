<?php

namespace App\Service\Trading;

class PositionEvaluator
{
    public function evaluate(object $position): array
    {
        // Sécurité: valeurs attendues
        $side     = strtoupper($position->side ?? 'UNKNOWN'); // LONG | SHORT
        $qty      = (float)($position->quantity ?? 0);
        $entry    = (float)($position->entryPrice ?? 0);
        $mark     = (float)($position->markPrice ?? 0);
        $lev      = (float)($position->leverage ?? 1.0);

        // 1) PnL absolu (contrats * variation) en tenant compte du côté
        $direction = ($side === 'LONG') ? 1 : (($side === 'SHORT') ? -1 : 0);
        $pnl = ($mark - $entry) * $qty * $direction;

        // 2) Variation du prix (brute, puis "effet position" signé par le côté)
        $priceChangePct = ($entry > 0) ? (($mark - $entry) / $entry) * 100 : 0.0;      // mouvement de prix
        $positionEffectPct = $priceChangePct * $direction;                              // effet pour la position
        $roiPct = $positionEffectPct * ($lev > 0 ? $lev : 1);                           // ROI levier
        // (optionnel) PnL% sur la marge si dispo: pnl / margin * 100

        // 3) Risk/Reward + distance SL/TP si fournis
        $rr = null; $distToSl = null; $distToTp = null; $rMultiple = null;
        $hasSl = isset($position->stopLoss)   && is_numeric($position->stopLoss);
        $hasTp = isset($position->takeProfit) && is_numeric($position->takeProfit);
        $sl = $hasSl ? (float)$position->stopLoss : null;
        $tp = $hasTp ? (float)$position->takeProfit : null;

        if ($entry > 0 && ($hasSl || $hasTp)) {
            // risque & gain en "prix", adaptés au côté
            if ($side === 'LONG') {
                $risk   = $hasSl ? max($entry - $sl, 0) : null;
                $reward = $hasTp ? max($tp - $entry, 0) : null;
                $distToSl = $hasSl ? (($mark - $sl) / $sl) * 100 : null;
                $distToTp = $hasTp ? (($tp - $mark) / $mark) * 100 : null;
            } elseif ($side === 'SHORT') {
                $risk   = $hasSl ? max($sl - $entry, 0) : null;
                $reward = $hasTp ? max($entry - $tp, 0) : null;
                $distToSl = $hasSl ? (($sl - $mark) / $sl) * 100 : null;
                $distToTp = $hasTp ? (($mark - $tp) / $mark) * 100 : null;
            } else {
                $risk = $reward = null;
            }

            $rr = ($risk && $risk > 0 && $reward !== null) ? round($reward / $risk, 2) : null;

            // R multiple courant (distance parcourue / risque)
            if ($risk && $risk > 0) {
                $progress = abs($mark - $entry);
                $rMultiple = round($progress / $risk, 2);
            }
        }

        // 4) Liquidation guard (si fourni)
        $liquidationRisk = null;
        if (isset($position->liqPrice) && is_numeric($position->liqPrice) && (float)$position->liqPrice > 0) {
            $liq = (float)$position->liqPrice;
            $liquidationRisk = round((($mark - $liq) / $liq) * 100, 2); // >0: à l'écart, <0: sous le prix de liq
        }

        // 5) Temps en position (si openTime fourni en ms ou s)
        $timeInPositionSec = null;
        if (isset($position->openTime) && is_numeric($position->openTime)) {
            $openTime = (int)$position->openTime;
            if ($openTime > 0) {
                // compat ms vs s
                if ($openTime > 2_000_000_000) { $openTime = (int)floor($openTime / 1000); }
                $timeInPositionSec = max(0, time() - $openTime);
            }
        }

        // 6) Statut & label de risque
        $riskLabel = $pnl >= 0 ? 'Profitable' : 'Perte';
        // Seuils: adapte si besoin (ex: neutre si |ROI|<0.2%)
        $status = (abs($roiPct) < 0.2) ? 'Neutre' : ($roiPct > 0 ? 'OK' : 'ALERTE');

        return [
            // PnL
            'pnl'                => round($pnl, 3),
            // Prix / ROI
            'price_change_pct'   => round($priceChangePct, 3),    // mouvement brut du prix
            'position_effect_pct'=> round($positionEffectPct, 3), // effet pour la position (signé côté)
            'roi_pct'            => round($roiPct, 3),
            // Risque
            'rr_ratio'           => $rr,
            'r_multiple'         => $rMultiple,
            'dist_to_sl_pct'     => $distToSl !== null ? round($distToSl, 2) : null,
            'dist_to_tp_pct'     => $distToTp !== null ? round($distToTp, 2) : null,
            'liq_risk_pct'       => $liquidationRisk,
            // Contexte
            'risk_label'         => $riskLabel,
            'status'             => $status,
            'time_in_position_s' => $timeInPositionSec,
        ];
    }
}
