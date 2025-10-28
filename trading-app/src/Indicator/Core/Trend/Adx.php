<?php
// src/Service/Indicator/Trend/Adx.php
namespace App\Indicator\Core\Trend;

use App\Indicator\Core\IndicatorInterface;

 
class Adx implements IndicatorInterface
{
    /**
     * Description textuelle de l'ADX (Average Directional Index).
     */
    public function getDescription(bool $detailed = false): string
    {
        if (!$detailed) {
            return "ADX: mesure la force de tendance via +DI et -DI (lissage Wilder).";
        }
        return implode("\n", [
            'ADX (Wilder):',
            '- TR_t = max(high_t-low_t, |high_t-close_{t-1}|, |low_t-close_{t-1}|).',
            '- +DM_t = max(high_t - high_{t-1}, 0) si supérieur à -DM; -DM_t = max(low_{t-1} - low_t, 0).',
            '- ATR, +DM, -DM lissés (Wilder).',
            '- +DI = 100 * (Smoothed(+DM) / ATR), -DI = 100 * (Smoothed(-DM) / ATR).',
            '- DX = 100 * |+DI - -DI| / (+DI + -DI).',
            '- ADX = lissage de DX (init = premier DX).',
        ]);
    }

    /**
     * Dernière valeur ADX( period ), lissage Wilder, init "first DX".
     * @param float[] $highs
     * @param float[] $lows
     * @param float[] $closes
     */
    public function calculate(array $highs, array $lows, array $closes, int $period = 14): float
    {
        $full = $this->calculateFull($highs, $lows, $closes, $period);
        return empty($full['adx']) ? 0.0 : (float) end($full['adx']);
    }

    /**
     * Séries complètes (ADX, +DI, -DI) – utile pour debug/comparaison.
     * Lissage Wilder, premier ADX = premier DX (style BitMart).
     * @return array{adx: float[], plus_di: float[], minus_di: float[]}
     */
    public function calculateFull(array $highs, array $lows, array $closes, int $period = 14): array
    {
        // Prefer TRADER extension if available
        if (function_exists('trader_adx')) {
            $adxArr = \trader_adx($highs, $lows, $closes, $period);
            $pdiArr = function_exists('trader_plus_di') ? \trader_plus_di($highs, $lows, $closes, $period) : null;
            $mdiArr = function_exists('trader_minus_di') ? \trader_minus_di($highs, $lows, $closes, $period) : null;
            if (is_array($adxArr)) {
                return [
                    'adx'      => array_values(array_map('floatval', $adxArr)),
                    'plus_di'  => is_array($pdiArr) ? array_values(array_map('floatval', $pdiArr)) : [],
                    'minus_di' => is_array($mdiArr) ? array_values(array_map('floatval', $mdiArr)) : [],
                ];
            }
        }

        $n = count($closes);
        if ($n <= $period) {
            return ['adx' => [], 'plus_di' => [], 'minus_di' => []];
        }

        $trs = $plusDM = $minusDM = [];
        for ($i = 1; $i < $n; $i++) {
            $up   = $highs[$i] - $highs[$i - 1];
            $down = $lows[$i - 1] - $lows[$i];

            $trs[]     = max($highs[$i] - $lows[$i], abs($highs[$i] - $closes[$i - 1]), abs($lows[$i] - $closes[$i - 1]));
            $plusDM[]  = ($up > $down && $up > 0) ? $up : 0.0;
            $minusDM[] = ($down > $up && $down > 0) ? $down : 0.0;
        }

        // moyennes initiales (simple) sur la fenêtre
        $atr0 = array_sum(array_slice($trs, 0, $period)) / $period;
        $pdm0 = array_sum(array_slice($plusDM, 0, $period)) / $period;
        $mdm0 = array_sum(array_slice($minusDM, 0, $period)) / $period;

        $atr  = [$atr0];
        $pdm  = [$pdm0];
        $mdm  = [$mdm0];

        // lissage Wilder récursif
        for ($i = $period; $i < count($trs); $i++) {
            $atr[] = (($atr[count($atr)-1] * ($period - 1)) + $trs[$i]) / $period;
            $pdm[] = (($pdm[count($pdm)-1] * ($period - 1)) + $plusDM[$i]) / $period;
            $mdm[] = (($mdm[count($mdm)-1] * ($period - 1)) + $minusDM[$i]) / $period;
        }

        $plusDI = $minusDI = $dx = [];
        $m = count($atr);
        for ($i = 0; $i < $m; $i++) {
            $pDI = $atr[$i] == 0.0 ? 0.0 : 100.0 * ($pdm[$i] / $atr[$i]);
            $mDI = $atr[$i] == 0.0 ? 0.0 : 100.0 * ($mdm[$i] / $atr[$i]);
            $plusDI[]  = $pDI;
            $minusDI[] = $mDI;
            $dx[]      = ($pDI + $mDI == 0.0) ? 0.0 : 100.0 * abs($pDI - $mDI) / ($pDI + $mDI);
        }

        // ADX: init = 1er DX, puis lissage Wilder
        $adx = [];
        if (!empty($dx)) {
            $adx[0] = $dx[0];
            for ($i = 1; $i < count($dx); $i++) {
                $adx[$i] = (($adx[$i-1] * ($period - 1)) + $dx[$i]) / $period;
            }
        }

        return ['adx' => $adx, 'plus_di' => $plusDI, 'minus_di' => $minusDI];
    }

    // Generic interface wrappers
    public function calculateValue(mixed ...$args): mixed
    {
        /** @var array $highs */
        $highs = $args[0] ?? [];
        $lows  = $args[1] ?? [];
        $closes= $args[2] ?? [];
        $period= isset($args[3]) ? (int)$args[3] : 14;
        return $this->calculate($highs, $lows, $closes, $period);
    }

    public function calculateSeries(mixed ...$args): array
    {
        /** @var array $highs */
        $highs = $args[0] ?? [];
        $lows  = $args[1] ?? [];
        $closes= $args[2] ?? [];
        $period= isset($args[3]) ? (int)$args[3] : 14;
        return $this->calculateFull($highs, $lows, $closes, $period);
    }
}
