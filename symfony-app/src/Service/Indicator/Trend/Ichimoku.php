<?php
// src/Service/Indicator/Trend/Ichimoku.php
namespace App\Service\Indicator\Trend;

class Ichimoku
{
    /**
     * Retour minimal pour usage direct (Tenkan/Kijun/SpanB).
     * Ignore la bougie non clôturée.
     * @return array{tenkan: float, kijun: float, senkou_b: float}
     */
    public function calculate(array $highs, array $lows, int $tenkan=9, int $kijun=26, int $spanB=52, bool $ignoreLastUnclosed=true): array
    {
        if ($ignoreLastUnclosed && count($highs) > 0) {
            array_pop($highs); array_pop($lows);
        }
        $n = count($highs);
        if ($n < $spanB) {
            return ['tenkan'=>0.0,'kijun'=>0.0,'senkou_b'=>0.0];
        }

        $tenkanVal = (max(array_slice($highs, -$tenkan)) + min(array_slice($lows, -$tenkan))) / 2.0;
        $kijunVal  = (max(array_slice($highs, -$kijun))  + min(array_slice($lows, -$kijun)))  / 2.0;
        $spanBVal  = (max(array_slice($highs, -$spanB))  + min(array_slice($lows, -$spanB)))  / 2.0;

        return [
            'tenkan'   => round($tenkanVal, 2),
            'kijun'    => round($kijunVal,  2),
            'senkou_b' => round($spanBVal,  2),
        ];
    }

    /**
     * Version complète : Tenkan, Kijun, Senkou A/B (+26), Chikou.
     * @return array{tenkan:float,kijun:float,senkou_a:float,senkou_b:float,chikou:float}
     */
    public function calculateFull(array $highs, array $lows, array $closes, int $tenkan=9, int $kijun=26, int $spanB=52, int $projectAhead=26, bool $ignoreLastUnclosed=true): array
    {
        if ($ignoreLastUnclosed && count($highs) > 0) {
            array_pop($highs); array_pop($lows); array_pop($closes);
        }
        $n = count($highs);
        if ($n < $spanB) {
            return ['tenkan'=>0.0,'kijun'=>0.0,'senkou_a'=>0.0,'senkou_b'=>0.0,'chikou'=>0.0];
        }

        $tenkanVal = (max(array_slice($highs, -$tenkan)) + min(array_slice($lows, -$tenkan))) / 2.0;
        $kijunVal  = (max(array_slice($highs, -$kijun))  + min(array_slice($lows, -$kijun)))  / 2.0;
        $senkouA   = ($tenkanVal + $kijunVal) / 2.0;                         // projeté +26 à l’affichage
        $senkouB   = (max(array_slice($highs, -$spanB)) + min(array_slice($lows, -$spanB))) / 2.0;
        $chikou    = $closes[$n-1] ?? 0.0;                                   // close décalé -26 à l’affichage

        return [
            'tenkan'    => round($tenkanVal, 2),
            'kijun'     => round($kijunVal,  2),
            'senkou_a'  => round($senkouA,   2),
            'senkou_b'  => round($senkouB,   2),
            'chikou'    => round($chikou,    2),
        ];
    }
}
