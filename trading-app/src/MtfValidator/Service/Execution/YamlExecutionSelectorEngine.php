<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Execution;

use App\Contract\MtfValidator\Dto\TimeframeDecisionDto;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Lazy;

#[Lazy]
#[AsAlias]
class YamlExecutionSelectorEngine implements ExecutionSelectorEngineInterface
{
    public function select(array $decisionsByTf, array $selectorConfig): ?array
    {
        $perTf = $selectorConfig['per_timeframe'] ?? [];

        // On suppose que les TF sont déjà connus: '15m', '5m', '1m'
        // L'ordre logique = 15m -> 5m -> 1m
        $order = ['15m', '5m', '1m'];

        // 1) On part du TF "source" (ex: 15m pour regular & scalper)
        foreach ($order as $tf) {
            if (!isset($decisionsByTf[$tf])) {
                continue;
            }

            $decision = $decisionsByTf[$tf];
            if (!$decision->valid) {
                continue;
            }

            $cfgTf = $perTf[$tf] ?? [];

            // stay_on_if : si toutes les conditions sont ok -> on reste sur ce TF
            if ($this->checkStayOnIf($decision, $cfgTf['stay_on_if'] ?? [])) {
                $side = $this->resolveSide($decision);
                if ($side !== null) {
                    return ['timeframe' => $tf, 'side' => $side];
                }
            }

            // drop_to_lower_if_any : si au moins une condition est vraie, on autorise la descente
            // forbid_drop_to_lower_if_any : blocage éventuel
            $shouldDrop = $this->checkDropToLowerIfAny($decisionsByTf, $cfgTf['drop_to_lower_if_any'] ?? []);
            $forbidDrop = $this->checkForbidDropToLowerIfAny($decisionsByTf, $cfgTf['forbid_drop_to_lower_if_any'] ?? []);

            if ($shouldDrop && !$forbidDrop) {
                // On tente le TF suivant dans l’ordre
                $lowerTf = $this->getLowerTimeframe($tf, $order);
                if ($lowerTf !== null && isset($decisionsByTf[$lowerTf])) {
                    $lowerDecision = $decisionsByTf[$lowerTf];
                    if ($lowerDecision->valid) {
                        $side = $this->resolveSide($lowerDecision) ?? $this->resolveSide($decision);
                        if ($side !== null) {
                            return ['timeframe' => $lowerTf, 'side' => $side];
                        }
                    }
                }
            }

            // Si stay_on_if est vide (cas scalper 5m/1m), on laisse la main
            // à la logique fallback (plus bas) si rien n’a matché ici.
        }

        // Fallback : premier TF valide avec side non neutre
        foreach ($order as $tf) {
            if (!isset($decisionsByTf[$tf])) {
                continue;
            }
            $d = $decisionsByTf[$tf];
            if (!$d->valid) {
                continue;
            }
            $side = $this->resolveSide($d);
            if ($side !== null) {
                return ['timeframe' => $tf, 'side' => $side];
            }
        }

        return null;
    }

    /**
     * @param array<int,mixed> $conditions
     */
    private function checkStayOnIf(TimeframeDecisionDto $decision, array $conditions): bool
    {
        // Cas spécial scalper 'get_false: true' -> toujours FALSE
        foreach ($conditions as $cond) {
            if (\is_array($cond) && ($cond['get_false'] ?? false) === true) {
                return false;
            }
        }

        foreach ($conditions as $cond) {
            if (!$this->evaluateSelectorCondition($decision, $cond)) {
                return false;
            }
        }

        return !empty($conditions);
    }

    /**
     * @param array<string,TimeframeDecisionDto> $decisionsByTf
     * @param array<int,mixed>                   $conditions
     */
    private function checkDropToLowerIfAny(array $decisionsByTf, array $conditions): bool
    {
        if (empty($conditions)) {
            return false;
        }

        // Ici, les conditions se basent typiquement sur 15m (expected_r_multiple, atr_pct_15m_bps, etc.)
        // On pourrait passer le TF source en param si besoin.
        // Pour simplifier, on prend la première décision du tableau:
        $decision = \reset($decisionsByTf);
        if (!$decision instanceof TimeframeDecisionDto) {
            return false;
        }

        foreach ($conditions as $cond) {
            if ($this->evaluateSelectorCondition($decision, $cond)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,TimeframeDecisionDto> $decisionsByTf
     * @param array<int,mixed>                   $conditions
     */
    private function checkForbidDropToLowerIfAny(array $decisionsByTf, array $conditions): bool
    {
        if (empty($conditions)) {
            return false;
        }

        // Ces conditions visent généralement le TF plus bas (5m) : adx_5m_lt, spread_bps_gt
        // On prend la décision 5m si présente, sinon on retombe sur la 1ère.
        $decision = $decisionsByTf['5m'] ?? \reset($decisionsByTf);
        if (!$decision instanceof TimeframeDecisionDto) {
            return false;
        }

        foreach ($conditions as $cond) {
            if ($this->evaluateSelectorCondition($decision, $cond)) {
                return true;
            }
        }

        return false;
    }

    private function resolveSide(TimeframeDecisionDto $decision): ?string
    {
        return \in_array($decision->signal, ['long', 'short'], true)
            ? $decision->signal
            : null;
    }

    /**
     * @param array<int,string> $order
     */
    private function getLowerTimeframe(string $current, array $order): ?string
    {
        $idx = \array_search($current, $order, true);
        if ($idx === false) {
            return null;
        }

        return $order[$idx + 1] ?? null;
    }

    /**
     * Interprète une condition du selector, par ex:
     *   - expected_r_multiple_gte: 2.0
     *   - entry_zone_width_pct_gt: 1.2
     *   - atr_pct_15m_gt_bps: 130
     *   - adx_5m_lt: 20
     *   - spread_bps_gt: 8
     *
     * @param array<string,mixed> $cond
     */
    private function evaluateSelectorCondition(TimeframeDecisionDto $decision, array $cond): bool
    {
        foreach ($cond as $key => $value) {
            // decode suffix (_gte, _lte, _gt, _lt)
            if (\is_bool($value)) {
                // cas type: { scalping: true } dans legacy allow_1m_only_for
                $flags = $decision->extra['flags'] ?? [];
                return ($flags[$key] ?? false) === $value;
            }

            $value = (float) $value;

            if (\str_ends_with($key, '_gte')) {
                $metric = \substr($key, 0, -4);
                return $this->getMetric($decision, $metric) >= $value;
            }

            if (\str_ends_with($key, '_lte')) {
                $metric = \substr($key, 0, -4);
                return $this->getMetric($decision, $metric) <= $value;
            }

            if (\str_ends_with($key, '_gt')) {
                $metric = \substr($key, 0, -3);
                return $this->getMetric($decision, $metric) > $value;
            }

            if (\str_ends_with($key, '_lt')) {
                $metric = \substr($key, 0, -3);
                return $this->getMetric($decision, $metric) < $value;
            }
        }

        return false;
    }

    private function getMetric(TimeframeDecisionDto $decision, string $metricKey): float
    {
        $extra = $decision->extra;

        if (!\array_key_exists($metricKey, $extra)) {
            return \NAN;
        }

        return (float) $extra[$metricKey];
    }
}
