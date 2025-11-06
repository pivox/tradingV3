<?php
declare(strict_types=1);

namespace App\TradeEntry\RiskSizer;

use App\TradeEntry\Types\Side;
use App\TradeEntry\Pricing\TickQuantizer;

final class StopLossCalculator
{
    public function __construct() {}

    public function fromAtr(float $entry, Side $side, float $atr, float $k, int $precision): float
    {
        $raw = $side === Side::Long ? ($entry - $k * $atr) : ($entry + $k * $atr);
        $q = TickQuantizer::quantize($raw, $precision);

        $tick = TickQuantizer::tick($precision);
        if ($q <= 0.0) {
            $q = $tick; // borne basse
        }
        // Garantir au moins 1 tick d'écart avec l'entrée après quantification
        if ($side === Side::Long && $q >= $entry) {
            $q = TickQuantizer::quantize(max($entry - $tick, $tick), $precision);
        } elseif ($side === Side::Short && $q <= $entry) {
            $q = TickQuantizer::quantizeUp($entry + $tick, $precision);
        }

        return $q;
    }

    public function fromRisk(float $entry, Side $side, float $riskUsdt, int $size, float $contractSize, int $precision): float
    {
        $dMax = $riskUsdt / max($contractSize * $size, 1e-12);
        $raw = $side === Side::Long ? ($entry - $dMax) : ($entry + $dMax);
        $q = TickQuantizer::quantize($raw, $precision);

        $tick = TickQuantizer::tick($precision);
        if ($q <= 0.0) {
            $q = $tick;
        }
        // Garantir au moins 1 tick d'écart avec l'entrée après quantification
        if ($side === Side::Long && $q >= $entry) {
            $q = TickQuantizer::quantize(max($entry - $tick, $tick), $precision);
        } elseif ($side === Side::Short && $q <= $entry) {
            $q = TickQuantizer::quantizeUp($entry + $tick, $precision);
        }

        return $q;
    }

    public function conservative(Side $side, float $a, float $b): float
    {
        return $side === Side::Long ? min($a, $b) : max($a, $b);
    }
    public function fromPivot(
        float $entry,
        Side $side,
        array $pivotLevels,
        string $policy = 'nearest_below',
        ?float $bufferPct = 0.0015,
        int $pricePrecision = 2
    ): float {
        if (empty($pivotLevels)) {
            throw new \InvalidArgumentException('Aucun pivot fourni pour calcul du stop pivot');
        }

        if ($side === Side::Long) {
            // Trouver le pivot juste en dessous de l’entrée (S1, S2, etc.)
            $candidates = array_filter($pivotLevels, fn($v, $k) =>
                str_starts_with(strtolower($k), 's') && $v < $entry,
                ARRAY_FILTER_USE_BOTH
            );
            if (!empty($candidates)) {
                $candidates = array_change_key_case($candidates, CASE_LOWER);
            }

            if (empty($candidates)) {
                throw new \RuntimeException('Aucun pivot inférieur à l’entrée trouvé pour un long');
            }

            // Appliquer la politique (nearest ou strongest)
            $policyKey = strtolower($policy);
            $pivot = null;

            if (str_starts_with($policyKey, 's') && isset($candidates[$policyKey])) {
                $pivot = $candidates[$policyKey];
            } elseif ($policyKey === 'strongest_below') {
                // Priorité aux supports les plus forts (S2, S1, S3, puis S4, S5, S6)
                foreach (['s2', 's1', 's3', 's4', 's5', 's6'] as $preferred) {
                    if (isset($candidates[$preferred])) {
                        $pivot = $candidates[$preferred];
                        break;
                    }
                }
            }

            if ($pivot === null) {
                $pivot = $policyKey === 'nearest_below'
                    ? max($candidates)
                    : reset($candidates);
            }

            // Ajouter un buffer (sous le pivot)
            $stop = $pivot * (1 - abs($bufferPct ?? 0.0));
            return TickQuantizer::quantize($stop, $pricePrecision);
        }

        // SHORT — pivot au-dessus de l’entrée
        $candidates = array_filter($pivotLevels, fn($v, $k) =>
            str_starts_with(strtolower($k), 'r') && $v > $entry,
            ARRAY_FILTER_USE_BOTH
        );
        if (!empty($candidates)) {
            $candidates = array_change_key_case($candidates, CASE_LOWER);
        }

        if (empty($candidates)) {
            throw new \RuntimeException('Aucun pivot supérieur à l’entrée trouvé pour un short');
        }

        $policyKey = strtolower($policy);
        $pivot = null;

        if (str_starts_with($policyKey, 'r') && isset($candidates[$policyKey])) {
            $pivot = $candidates[$policyKey];
        } elseif ($policyKey === 'strongest_above') {
            // Priorité aux résistances les plus fortes (R2, R1, R3, puis R4, R5, R6)
            foreach (['r2', 'r1', 'r3', 'r4', 'r5', 'r6'] as $preferred) {
                if (isset($candidates[$preferred])) {
                    $pivot = $candidates[$preferred];
                    break;
                }
            }
        }

        if ($pivot === null) {
            $pivot = $policyKey === 'nearest_above'
                ? min($candidates)
                : reset($candidates);
        }

        $stop = $pivot * (1 + abs($bufferPct ?? 0.0));
        return TickQuantizer::quantizeUp($stop, $pricePrecision);
    }

}
