<?php
declare(strict_types=1);

namespace App\TradeEntry\RiskSizer;

use App\TradeEntry\Types\Side;
use App\TradeEntry\Pricing\TickQuantizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class TakeProfitCalculator
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.positions_flow')] private readonly LoggerInterface $flowLogger,
        #[Autowire(service: 'monolog.logger.order_journey')] private readonly LoggerInterface $journeyLogger,
    ) {}

    public function fromRMultiple(float $entry, float $stop, Side $side, float $rMultiple, int $precision): float
    {
        $distance = abs($entry - $stop);
        $tp = $side === Side::Long ? ($entry + $rMultiple * $distance) : ($entry - $rMultiple * $distance);

        return TickQuantizer::quantize($tp, $precision);
    }

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
        ?string $decisionKey
    ): float {
        $riskUnit = $side === Side::Long ? $entry - $stop : $stop - $entry;
        if ($riskUnit <= 0.0) {
            return $baseTakeProfit;
        }

        $tpTheoretical = $side === Side::Long
            ? $entry + $rMultiple * $riskUnit
            : $entry - $rMultiple * $riskUnit;

        $pivots = $this->collectPivotsForSide($side, $entry, $pivotLevels);
        $candidate = $this->choosePivotCandidate($pivots, $side, $tpTheoretical, $entry, $riskUnit, $rMultiple, $policy, $maxExtraR);

        $tpRaw = $candidate ?? $tpTheoretical;
        $tpRaw = $this->applyTpBuffer($side, $tpRaw, $bufferPct, $bufferTicks, $tick);

        if ($side === Side::Long) {
            $tpRaw = max($tpRaw, $entry + $tick);
            $tpFinal = TickQuantizer::quantizeUp($tpRaw, $pricePrecision);
            if ($tpFinal <= $entry) {
                $tpFinal = TickQuantizer::quantizeUp($entry + $tick, $pricePrecision);
            }
        } else {
            $tpRaw = min($tpRaw, $entry - $tick);
            $tpFinal = TickQuantizer::quantize($tpRaw, $pricePrecision);
            if ($tpFinal >= $entry) {
                $tpFinal = TickQuantizer::quantize($entry - $tick, $pricePrecision);
            }
        }

        $effectiveK = abs($tpFinal - $entry) / $riskUnit;
        if ($effectiveK < $minKeepRatio * $rMultiple) {
            $tpFinal = $side === Side::Long
                ? TickQuantizer::quantizeUp(max($tpTheoretical, $entry + $tick), $pricePrecision)
                : TickQuantizer::quantize(min($tpTheoretical, $entry - $tick), $pricePrecision);
        }

        $this->flowLogger->info('order_plan.tp_aligned', [
            'symbol' => $symbol,
            'side' => $side->value,
            'entry' => $entry,
            'stop' => $stop,
            'risk_unit' => $riskUnit,
            'tp_theoretical' => $tpTheoretical,
            'tp_candidate' => $candidate,
            'tp_final' => $tpFinal,
            'policy' => $policy,
            'buffer_pct' => $bufferPct,
            'buffer_ticks' => $bufferTicks,
            'effective_k' => $effectiveK,
            'decision_key' => $decisionKey,
        ]);

        $this->journeyLogger->info('order_journey.plan_builder.tp_aligned', [
            'symbol' => $symbol,
            'decision_key' => $decisionKey,
            'policy' => $policy,
            'tp_theoretical' => $tpTheoretical,
            'tp_candidate' => $candidate,
            'tp_final' => $tpFinal,
            'buffer_pct' => $bufferPct,
            'buffer_ticks' => $bufferTicks,
            'effective_k' => $effectiveK,
            'min_keep_ratio' => $minKeepRatio,
        ]);

        return $tpFinal;
    }

    /**
     * @return float[]
     */
    private function collectPivotsForSide(Side $side, float $entry, array $pivotLevels): array
    {
        $levels = [];
        $pp = $pivotLevels['pp'] ?? null;

        if ($side === Side::Long) {
            if ($pp !== null && $entry < $pp) {
                $levels[] = $pp;
            }
            foreach (['r1', 'r2', 'r3', 'r4', 'r5', 'r6'] as $key) {
                if (isset($pivotLevels[$key])) {
                    $levels[] = $pivotLevels[$key];
                }
            }
            $levels = array_values(array_filter($levels, static fn($v) => is_finite((float)$v)));
            sort($levels, SORT_NUMERIC);
        } else {
            if ($pp !== null && $entry > $pp) {
                $levels[] = $pp;
            }
            foreach (['s1', 's2', 's3', 's4', 's5', 's6'] as $key) {
                if (isset($pivotLevels[$key])) {
                    $levels[] = $pivotLevels[$key];
                }
            }
            $levels = array_values(array_filter($levels, static fn($v) => is_finite((float)$v)));
            rsort($levels, SORT_NUMERIC);
        }

        return $levels;
    }

    private function choosePivotCandidate(
        array $pivots,
        Side $side,
        float $tpTheoretical,
        float $entry,
        float $riskUnit,
        float $rMultiple,
        string $policy,
        ?float $maxExtraR
    ): ?float {
        if (empty($pivots)) {
            return null;
        }

        $candidate = null;
        if ($side === Side::Long) {
            foreach ($pivots as $pivot) {
                if ($pivot >= $tpTheoretical) {
                    $candidate = $pivot;
                    break;
                }
            }
        } else {
            foreach ($pivots as $pivot) {
                if ($pivot <= $tpTheoretical) {
                    $candidate = $pivot;
                    break;
                }
            }
        }

        if ($candidate === null) {
            return null;
        }

        if ($policy === 'pivot_aggressive' && $maxExtraR !== null) {
            $candidateKR = abs($candidate - $entry) / $riskUnit;
            if ($candidateKR > $rMultiple + $maxExtraR) {
                return null;
            }
        }

        return $candidate;
    }

    private function applyTpBuffer(Side $side, float $tp, ?float $bufferPct, ?int $bufferTicks, float $tick): float
    {
        $result = $tp;

        if ($bufferPct !== null && $bufferPct > 0.0) {
            $factor = $bufferPct;
            $result = $side === Side::Long
                ? $result * (1.0 - $factor)
                : $result * (1.0 + $factor);
        }

        if ($bufferTicks !== null && $bufferTicks > 0) {
            $offset = $bufferTicks * $tick;
            $result = $side === Side::Long ? $result - $offset : $result + $offset;
        }

        return $result;
    }
}
