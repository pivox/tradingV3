<?php
declare(strict_types=1);

namespace App\TradeEntry\RiskSizer;

use App\TradeEntry\Pricing\TickQuantizer;
use App\TradeEntry\Types\Side;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class TakeProfitCalculator
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.positions_flow')] private readonly LoggerInterface $log
    ) {}

    /**
     * TP = entry ± R * multiple
     */
    public function fromRMultiple(
        float $entry,
        float $stop,
        Side $side,
        float $rMultiple,
        int $pricePrecision
    ): float {
        $rMultiple = max(0.0, $rMultiple);
        $riskUnit  = abs($entry - $stop);
        if ($riskUnit <= 0.0 || $rMultiple === 0.0) {
            return TickQuantizer::quantize($entry, $pricePrecision);
        }

        $tp = $side === Side::Long
            ? $entry + $rMultiple * $riskUnit
            : $entry - $rMultiple * $riskUnit;

        return $side === Side::Long
            ? TickQuantizer::quantize($tp, $pricePrecision)
            : TickQuantizer::quantizeUp($tp, $pricePrecision);
    }

    /**
     * Aligne le TP sur un niveau pivot (R1/R2/R3... ou S1/S2/S3...) cohérent :
     *  - pour un Long : chercher la résistance >= baseTP, sinon garder baseTP
     *  - pour un Short: chercher le support   <= baseTP, sinon garder baseTP
     * Applique buffer (%) et/ou ticks côté "inside", puis garde minKeepRatio en R et cap maxExtraR.
     */
    public function alignTakeProfitWithPivot(
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
        ?string $decisionKey = null
    ): float {
        $risk = abs($entry - $stop);
        if ($risk <= 0.0) {
            return $baseTakeProfit;
        }

        // Normaliser les pivots en liste de floats
        $levels = $this->extractNumericPivotLevels($pivotLevels);
        if (empty($levels)) {
            return $baseTakeProfit;
        }

        sort($levels); // tri croissant

        $pick = $baseTakeProfit;

        if ($side === Side::Long) {
            // Résistance la plus proche >= baseTP
            $candidates = array_values(array_filter($levels, fn($p) => $p >= $baseTakeProfit));
            if (!empty($candidates)) {
                $pivot = $candidates[0];
                // Buffer : pour Long, placer LÉGÈREMENT en dessous de la résistance
                if ($bufferPct !== null && $bufferPct > 0.0) {
                    $pivot *= (1.0 - $bufferPct);
                }
                if ($bufferTicks !== null && $bufferTicks > 0) {
                    $pivot -= $bufferTicks * $tick;
                }
                $pick = max($baseTakeProfit, $pivot);
            }
        } else {
            // Support le plus proche <= baseTP
            $candidates = array_values(array_filter($levels, fn($p) => $p <= $baseTakeProfit));
            if (!empty($candidates)) {
                $pivot = $candidates[count($candidates) - 1];
                // Buffer : pour Short, placer LÉGÈREMENT au-dessus du support
                if ($bufferPct !== null && $bufferPct > 0.0) {
                    $pivot *= (1.0 + $bufferPct);
                }
                if ($bufferTicks !== null && $bufferTicks > 0) {
                    $pivot += $bufferTicks * $tick;
                }
                $pick = min($baseTakeProfit, $pivot);
            }
        }

        // Gardes en "R"
        $rTheo = $rMultiple;
        $rEff  = $risk > 0.0
            ? (($side === Side::Long ? ($pick - $entry) : ($entry - $pick)) / $risk)
            : 0.0;

        // (1) Ne pas descendre sous minKeepRatio * R
        if ($rTheo > 0.0 && $rEff < max(0.0, $minKeepRatio * $rTheo)) {
            // garde le TP théorique si le pivot "casse" trop le R
            $pick = $baseTakeProfit;
            $rEff = $rTheo;
        }

        // (2) Ne pas dépasser (R + maxExtraR)
        if ($maxExtraR !== null && $maxExtraR >= 0.0 && $rEff > $rTheo + $maxExtraR) {
            $capR = $rTheo + $maxExtraR;
            $pick = $side === Side::Long
                ? $entry + $capR * $risk
                : $entry - $capR * $risk;
        }

        // Quantize dans le bon sens
        $pick = $side === Side::Long
            ? TickQuantizer::quantize($pick, $pricePrecision)
            : TickQuantizer::quantizeUp($pick, $pricePrecision);

        $this->log->debug('tp.align_pivot', [
            'symbol' => $symbol,
            'policy' => $policy,
            'entry' => $entry,
            'stop' => $stop,
            'base_tp' => $baseTakeProfit,
            'final_tp' => $pick,
            'r_theoretical' => $rTheo,
            'r_effective' => $rEff,
            'min_keep_ratio' => $minKeepRatio,
            'max_extra_r' => $maxExtraR,
            'decision_key' => $decisionKey,
        ]);

        return $pick;
    }

    /**
     * Accepte un tableau de pivots hétérogènes et retourne la liste des floats valides.
     * Supporte clés usuelles: pp, r1..r3/r4, s1..s3/s4, etc.
     */
    private function extractNumericPivotLevels(array $pivotLevels): array
    {
        $out = [];
        $it = $pivotLevels;

        // Si déjà une liste de nombres
        if (array_is_list($it)) {
            foreach ($it as $v) {
                if (\is_numeric($v)) { $out[] = (float)$v; }
            }
            return $out;
        }

        // Sinon dictionnaire : on parcourt toutes les valeurs
        foreach ($it as $k => $v) {
            if (\is_array($v)) {
                foreach ($v as $vv) {
                    if (\is_numeric($vv)) { $out[] = (float)$vv; }
                }
            } elseif (\is_numeric($v)) {
                $out[] = (float)$v;
            }
        }
        return $out;
    }
}
