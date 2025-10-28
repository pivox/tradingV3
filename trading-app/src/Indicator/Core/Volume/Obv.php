<?php
namespace App\Indicator\Core\Volume;

use App\Indicator\Core\IndicatorInterface;
 
final class Obv implements IndicatorInterface
{
    /**
     * Description textuelle de l'OBV (On-Balance Volume).
     */
    public function getDescription(bool $detailed = false): string
    {
        if (!$detailed) {
            return "OBV: cumul de volumes positif si close monte, négatif si close baisse.";
        }
        return implode("\n", [
            'OBV:',
            '- OBV_0 = 0 (initialisation).',
            '- Si close_t > close_{t-1} => OBV_t = OBV_{t-1} + volume_t.',
            '- Si close_t < close_{t-1} => OBV_t = OBV_{t-1} - volume_t.',
            '- Sinon: OBV_t = OBV_{t-1}.',
        ]);
    }

    /**
     * @param float[] $closes
     * @param float[] $volumes
     * @return float[] OBV series (cumulative)
     */
    public function calculateFull(array $closes, array $volumes): array
    {
        // Prefer TRADER extension if available
        if (function_exists('trader_obv')) {
            $arr = \trader_obv($closes, $volumes);
            if (is_array($arr)) {
                return array_values(array_map('floatval', $arr));
            }
        }
        $n = min(count($closes), count($volumes));
        $out = [];
        if ($n === 0) return $out;

        $obv = 0.0;
        $out[] = $obv;
        for ($i = 1; $i < $n; $i++) {
            $v = (float)$volumes[$i];
            if ($closes[$i] > $closes[$i-1]) {
                $obv += $v;
            } elseif ($closes[$i] < $closes[$i-1]) {
                $obv -= $v;
            }
            $out[] = $obv;
        }
        return $out;
    }

    /**
     * @return float last OBV
     */
    public function calculate(array $closes, array $volumes): float
    {
        $s = $this->calculateFull($closes, $volumes);
        return empty($s) ? 0.0 : (float) end($s);
    }

    // Generic interface wrappers
    public function calculateValue(mixed ...$args): mixed
    {
        /** @var array $closes */
        $closes  = $args[0] ?? [];
        $volumes = $args[1] ?? [];
        return $this->calculate($closes, $volumes);
    }

    public function calculateSeries(mixed ...$args): array
    {
        /** @var array $closes */
        $closes  = $args[0] ?? [];
        $volumes = $args[1] ?? [];
        return $this->calculateFull($closes, $volumes);
    }
}
