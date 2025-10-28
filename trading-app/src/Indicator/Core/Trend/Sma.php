<?php
declare(strict_types=1);

namespace App\Indicator\Core\Trend;

use App\Indicator\Core\IndicatorInterface;

 
final class Sma implements IndicatorInterface
{
    public function getDescription(bool $detailed = false): string
    {
        if (!$detailed) {
            return 'SMA: moyenne mobile simple (moyenne arithmétique des prix).';
        }
        return implode("\n", [
            'Simple Moving Average (SMA):',
            '- SMA_t = (1/period) * Σ_{i=t-period+1..t} price_i.',
            '- Série: moyenne glissante sur chaque fenêtre de longueur period.',
        ]);
    }

    /**
     * Dernière valeur SMA ou null si insuffisant.
     * @param float[] $prices
     */
    public function calculate(array $prices, int $period): ?float
    {
        if (function_exists('trader_sma')) {
            $arr = \trader_sma($prices, $period);
            if (is_array($arr) && !empty($arr)) {
                $last = end($arr);
                return $last !== false ? (float)$last : null;
            }
        }
        $n = count($prices);
        if ($n < $period || $period <= 0) return null;
        $slice = array_slice($prices, -$period);
        return array_sum($slice) / $period;
    }

    /**
     * Série complète SMA (alignée sur fenêtres complètes).
     * @param float[] $prices
     * @return float[]
     */
    public function calculateFull(array $prices, int $period): array
    {
        if (function_exists('trader_sma')) {
            $arr = \trader_sma($prices, $period);
            return is_array($arr) ? array_values(array_map('floatval', $arr)) : [];
        }
        $n = count($prices);
        if ($n < $period || $period <= 0) return [];
        $out = [];
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += (float)$prices[$i];
            if ($i >= $period) { $sum -= (float)$prices[$i - $period]; }
            if ($i + 1 >= $period) { $out[] = $sum / $period; }
        }
        return $out;
    }

    // Generic wrappers
    public function calculateValue(mixed ...$args): mixed
    {
        $prices = $args[0] ?? [];
        $period = isset($args[1]) ? (int)$args[1] : 14;
        return $this->calculate($prices, $period);
    }

    public function calculateSeries(mixed ...$args): array
    {
        $prices = $args[0] ?? [];
        $period = isset($args[1]) ? (int)$args[1] : 14;
        return $this->calculateFull($prices, $period);
    }
}
