<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m', '1h', '1m', '5m'], side: 'long', name: self::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: self::NAME)]
final class MacdLineCrossUpWithHysteresisCondition extends AbstractCondition
{
    public const NAME = 'macd_line_cross_up_with_hysteresis';

    public function getName(): string { return self::NAME; }

    protected function getDefaultDescription(): string
    {
        return "Croisement haussier robuste MACD au-dessus de la ligne de signal avec hystérésis (anti-bruit).";
    }

    public function evaluate(array $context): ConditionResult
    {
        $minGap = $context['min_gap'] ?? 0.0003;
        $coolDownBars = (int)($context['cool_down_bars'] ?? 2);
        $requirePrevBelow = $context['require_prev_below'] ?? true;

        $macdHist = $context['macd_hist_last3'] ?? null; // oldest..latest
        if (!\is_array($macdHist) || count($macdHist) < 2) {
            return $this->result(self::NAME, false, null, (float)$minGap, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'MACD',
                'reason' => 'insufficient_series_values',
            ]));
        }

        $n = count($macdHist);
        $maxOffset = min($coolDownBars, $n - 2);
        $passed = false; $barsSince = null; $triggerGap = null; $prevGap = null;

        for ($off = 0; $off <= $maxOffset; $off++) {
            $curr = $macdHist[$n - 1 - $off];
            $prev = $macdHist[$n - 2 - $off];
            if (!\is_float($curr) || !\is_float($prev)) { continue; }

            $condNow = $curr >= $minGap;
            $condPrev = $prev <= -$minGap;
            if ($condNow && (!$requirePrevBelow || $condPrev)) {
                $passed = true;
                $barsSince = $off;
                $triggerGap = $curr;
                $prevGap = $prev;
                break;
            }
        }

        return $this->result(self::NAME, $passed, $triggerGap, (float) $minGap, $this->baseMeta($context, [
            'bars_since_cross' => $barsSince,
            'prev_gap' => $prevGap,
            'min_gap' => $minGap,
        ]));
    }
}
