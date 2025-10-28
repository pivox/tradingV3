<?php
// src/Service/Indicator/Trend/Ichimoku.php
namespace App\Indicator\Core\Trend;

use App\Indicator\Core\IndicatorInterface;

 
class Ichimoku implements IndicatorInterface
{
    /**
     * Description textuelle d'Ichimoku Kinko Hyo.
     */
    public function getDescription(bool $detailed = false): string
    {
        if (!$detailed) {
            return 'Ichimoku: Tenkan, Kijun, Senkou A/B, Chikou pour lecture de tendance/supports.';
        }
        return implode("\n", [
            'Ichimoku:',
            '- Tenkan = (max(high, 9) + min(low, 9)) / 2.',
            '- Kijun  = (max(high, 26) + min(low, 26)) / 2.',
            '- Senkou A = (Tenkan + Kijun) / 2 (affiché projeté +26).',
            '- Senkou B = (max(high, 52) + min(low, 52)) / 2 (affiché projeté +26).',
            '- Chikou = close décalé -26 (affichage).',
            '- Note: les décalages sont d’affichage; les valeurs retournées sont les niveaux bruts.',
        ]);
    }

    /**
     * Retour minimal pour usage direct (Tenkan/Kijun/SpanB).
     * Ignore la bougie non clôturée.
     * @return array{tenkan: float, kijun: float, senkou_b: float}
     */
    public function calculate(
        array $highs,
        array $lows,
        int $tenkan = 9,
        int $kijun = 26,
        int $spanB = 52,
        bool $ignoreLastUnclosed = true
    ): array {
        if ($ignoreLastUnclosed && count($highs) > 0) {
            array_pop($highs);
            array_pop($lows);
        }

        $n = count($highs);
        if ($n < $spanB) {
            return ['tenkan' => 0.0, 'kijun' => 0.0, 'senkou_b' => 0.0];
        }

        $tenkanVal = (max(array_slice($highs, -$tenkan)) + min(array_slice($lows, -$tenkan))) / 2.0;
        $kijunVal  = (max(array_slice($highs, -$kijun))  + min(array_slice($lows, -$kijun)))  / 2.0;
        $spanBVal  = (max(array_slice($highs, -$spanB))  + min(array_slice($lows, -$spanB)))  / 2.0;

        return [
            'tenkan'   => $tenkanVal,
            'kijun'    => $kijunVal,
            'senkou_b' => $spanBVal,
        ];
    }

    /**
     * Version complète : Tenkan, Kijun, Senkou A/B (+26), Chikou.
     * @return array{tenkan:float,kijun:float,senkou_a:float,senkou_b:float,chikou:float}
     */
    public function calculateFull(
        array $highs,
        array $lows,
        array $closes,
        int $tenkan = 9,
        int $kijun = 26,
        int $spanB = 52,
        int $projectAhead = 26,
        bool $ignoreLastUnclosed = true
    ): array {
        if ($ignoreLastUnclosed && count($highs) > 0) {
            array_pop($highs);
            array_pop($lows);
            array_pop($closes);
        }

        $n = count($highs);
        if ($n < $spanB) {
            return ['tenkan' => 0.0, 'kijun' => 0.0, 'senkou_a' => 0.0, 'senkou_b' => 0.0, 'chikou' => 0.0];
        }

        $tenkanVal = (max(array_slice($highs, -$tenkan)) + min(array_slice($lows, -$tenkan))) / 2.0;
        $kijunVal  = (max(array_slice($highs, -$kijun))  + min(array_slice($lows, -$kijun)))  / 2.0;
        $senkouA   = ($tenkanVal + $kijunVal) / 2.0; // projeté +26 à l’affichage
        $senkouB   = (max(array_slice($highs, -$spanB)) + min(array_slice($lows, -$spanB))) / 2.0;
        $chikou    = $closes[$n - 1] ?? 0.0;         // close décalé -26 à l’affichage

        return [
            'tenkan'    => $tenkanVal,
            'kijun'     => $kijunVal,
            'senkou_a'  => $senkouA,
            'senkou_b'  => $senkouB,
            'chikou'    => $chikou,
        ];
    }

    // Generic interface wrappers
    public function calculateValue(mixed ...$args): mixed
    {
        /** @var array $highs */
        $highs  = $args[0] ?? [];
        $lows   = $args[1] ?? [];
        $tenkan = isset($args[2]) ? (int)$args[2] : 9;
        $kijun  = isset($args[3]) ? (int)$args[3] : 26;
        $spanB  = isset($args[4]) ? (int)$args[4] : 52;
        $ignore = isset($args[5]) ? (bool)$args[5] : true;
        return $this->calculate($highs, $lows, $tenkan, $kijun, $spanB, $ignore);
    }

    public function calculateSeries(mixed ...$args): array
    {
        /** @var array $highs */
        $highs  = $args[0] ?? [];
        $lows   = $args[1] ?? [];
        $closes = $args[2] ?? [];
        $tenkan = isset($args[3]) ? (int)$args[3] : 9;
        $kijun  = isset($args[4]) ? (int)$args[4] : 26;
        $spanB  = isset($args[5]) ? (int)$args[5] : 52;
        $ahead  = isset($args[6]) ? (int)$args[6] : 26;
        $ignore = isset($args[7]) ? (bool)$args[7] : true;
        return $this->calculateFull($highs, $lows, $closes, $tenkan, $kijun, $spanB, $ahead, $ignore);
    }
}
