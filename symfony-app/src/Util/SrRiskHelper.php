<?php

namespace App\Util;


final class SrRiskHelper
{
    private const MIN_BUFFER_PCT = 0.0005;          // 0.05%
    private const BUFFER_RATIO   = 0.5;             // utilise au plus 50% de l'écart support/entrée
    private const MAX_ATR_DISTANCE_MULT = 1.2;      // ne pas élargir le stop à >120% de l'ATR prévu

    /**
     * @param array<int, array{open:float, high:float, low:float, close:float}> $klines  bougies triées ASC par temps
     * @param int   $fractalLR  nb de bougies à gauche/droite pour détecter les pivots (ex: 2 à 4)
     * @param float $clusterTol tolérance de regroupement des niveaux (ex: 0.15% = 0.0015)
     * @return array{supports: float[], resistances: float[], atr: float, donchian_low: float, donchian_high: float, pivots: array{pp:float,r1:float,r2:float,s1:float,s2:float}}
     */
    public static function findSupportResistance(array $klines, int $fractalLR = 3, float $clusterTol = 0.0015): array
    {
        $n = count($klines);
        if ($n < max(2 * $fractalLR + 1, 20)) {
            throw new \InvalidArgumentException("Not enough candles for S/R detection.");
        }

        // --- ATR(14) Wilder
        $atr = self::atr($klines, 14);

        // --- Donchian (lookback 20)
        $slice = array_slice($klines, -20);
        $donHigh = max(array_column($slice, 'high'));
        $donLow  = min(array_column($slice, 'low'));

        // --- Pivots classiques sur la dernière bougie clôturée
        $last = $klines[$n - 2]; // on ignore la bougie en cours
        $H = $last['high']; $L = $last['low']; $C = $last['close'];
        $pp = ($H + $L + $C) / 3.0;
        $r1 = 2*$pp - $L;
        $s1 = 2*$pp - $H;
        $r2 = $pp + ($H - $L);
        $s2 = $pp - ($H - $L);

        // --- Fractales (pivots H/L) puis clustering
        $pivH = []; $pivL = [];
        for ($i = $fractalLR; $i < $n - $fractalLR; $i++) {
            $isHigh = true; $isLow = true;
            for ($k = $i - $fractalLR; $k <= $i + $fractalLR; $k++) {
                if ($klines[$k]['high'] > $klines[$i]['high']) $isHigh = false;
                if ($klines[$k]['low']  < $klines[$i]['low'])  $isLow  = false;
                if (!$isHigh && !$isLow) break;
            }
            if ($isHigh) $pivH[] = $klines[$i]['high'];
            if ($isLow)  $pivL[] = $klines[$i]['low'];
        }

        $resistances = self::clusterLevels($pivH, $clusterTol);
        $supports    = self::clusterLevels($pivL, $clusterTol);

        // Ajoute les niveaux Donchian & Pivots aux listes puis re-cluster une fois
        $resistances = self::clusterLevels(array_merge($resistances, [$donHigh, $r1, $r2]), $clusterTol);
        $supports    = self::clusterLevels(array_merge($supports,    [$donLow,  $s1, $s2]), $clusterTol);

        sort($supports);
        sort($resistances);

        return [
            'supports'      => $supports,
            'resistances'   => $resistances,
            'atr'           => $atr,
            'donchian_low'  => $donLow,
            'donchian_high' => $donHigh,
            'pivots'        => ['pp' => $pp, 'r1' => $r1, 'r2' => $r2, 's1' => $s1, 's2' => $s2],
        ];
    }

    /** ATR(14) Wilder */
    private static function atr(array $klines, int $period = 14): float
    {
        $trs = [];
        for ($i = 1; $i < count($klines); $i++) {
            $h = $klines[$i]['high']; $l = $klines[$i]['low']; $pc = $klines[$i-1]['close'];
            $tr = max($h - $l, abs($h - $pc), abs($l - $pc));
            $trs[] = $tr;
        }
        if (count($trs) < $period) return 0.0;

        // Wilder EMA (ATR)
        $atr = array_sum(array_slice($trs, 0, $period)) / $period;
        $alpha = 1.0 / $period;
        for ($i = $period; $i < count($trs); $i++) {
            $atr = ($atr * ($period - 1) + $trs[$i]) / $period; // Wilder smoothing
        }
        return $atr;
    }

    /**
     * Regroupe des niveaux proches (tolérance relative, ex 0.15%).
     * @param float[] $levels
     * @return float[]
     */
    private static function clusterLevels(array $levels, float $tol): array
    {
        sort($levels);
        $clusters = [];
        foreach ($levels as $lvl) {
            if (empty($clusters)) { $clusters[] = [$lvl]; continue; }
            $lastIdx = count($clusters) - 1;
            $ref = array_sum($clusters[$lastIdx]) / count($clusters[$lastIdx]);
            $rel = abs($lvl - $ref) / max(1e-12, $ref);
            if ($rel <= $tol) {
                $clusters[$lastIdx][] = $lvl;
            } else {
                $clusters[] = [$lvl];
            }
        }
        // moyenne de cluster
        return array_map(fn($c) => array_sum($c) / count($c), $clusters);
    }

    /**
     * Choisit un SL logique à partir des S/R + ATR.
     *
     * @param string $side 'LONG'|'SHORT'
     * @param float  $entry
     * @param float[] $supports
     * @param float[] $resistances
     * @param float  $atr
     * @param float  $atrK   multiplicateur de buffer (ex: 1.5 à 2.0)
     * @return float  prix du SL
     */
    public static function chooseSlFromSr(
        string $side,
        float $entry,
        array $supports,
        array $resistances,
        float $atr,
        float $atrK = 1.5
    ): float {
        $side        = strtolower($side);
        $atrDistance = max($atrK * $atr, 1e-12);
        $minBuffer   = max(self::MIN_BUFFER_PCT * $entry, 1e-12);

        if ($side === 'long') {
            $fallback = max(1e-12, $entry - $atrDistance);
            $cands = array_values(array_filter($supports, fn($s) => $s < $entry));
            if (empty($cands)) {
                return $fallback;
            }
            $level = max($cands);
            $distanceToLevel = $entry - $level;
            if ($distanceToLevel <= 0.0) {
                return $fallback;
            }
            if ($distanceToLevel > $atrDistance * self::MAX_ATR_DISTANCE_MULT) {
                return $fallback;
            }
            if ($fallback >= $level) {
                return max(1e-12, $level - $minBuffer);
            }

            $buffer = min(
                $atrDistance,
                max($minBuffer, $distanceToLevel * self::BUFFER_RATIO)
            );
            $stop = $level - $buffer;
            $stop = max($stop, $fallback);

            return max(1e-12, $stop);
        }

        // SHORT : SL au-dessus de la résistance la plus proche > entry
        $fallback = $entry + $atrDistance;
        $cands = array_values(array_filter($resistances, fn($r) => $r > $entry));
        if (empty($cands)) {
            return $fallback;
        }
        $level = min($cands);
        $distanceToLevel = $level - $entry;
        if ($distanceToLevel <= 0.0) {
            return $fallback;
        }
        if ($distanceToLevel > $atrDistance * self::MAX_ATR_DISTANCE_MULT) {
            return $fallback;
        }
        if ($fallback <= $level) {
            return $level + $minBuffer;
        }

        $buffer = min(
            $atrDistance,
            max($minBuffer, $distanceToLevel * self::BUFFER_RATIO)
        );
        $stop = $level + $buffer;
        $stop = min($stop, $fallback);

        return max(1e-12, $stop);
    }

    /**
     * Levier optimal pour respecter un risque max sur la marge.
     * lev_opt = RiskMax% / stop%
     */
    public static function leverageFromRisk(float $entry, float $sl, float $riskMaxPercent, int $maxLev = 20, int $floorLev = 2): float
    {
        $stopPct = abs($sl - $entry) / max(1e-12, $entry); // en décimal
        if ($stopPct <= 0.0) return (float)$floorLev;
        $lev = $riskMaxPercent / $stopPct;
        return max($floorLev, min($maxLev, $lev));
    }
}
