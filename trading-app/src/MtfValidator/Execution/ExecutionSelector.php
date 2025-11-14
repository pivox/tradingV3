<?php

declare(strict_types=1);

namespace App\MtfValidator\Execution;

use App\Config\MtfValidationConfig;
use App\MtfValidator\ConditionLoader\ConditionRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ExecutionSelector
{
    public function __construct(
        private readonly MtfValidationConfig $mtfConfig,
        private readonly ConditionRegistry $registry,
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string,mixed> $context Context keys consumed by conditions (expected_r_multiple, atr_pct_15m_bps, ...)
     */
    public function decide(array $context): ExecutionDecision
    {
        // Use the dynamic config from ConditionRegistry if available; otherwise, fall back to the static config provided
        // at construction. This fallback is intentional and applies in all cases, not just during initialization.
        $activeConfig = $this->registry->getCurrentConfig() ?? $this->mtfConfig;
        $cfg = $activeConfig->getConfig();
        $selector = (array)($cfg['execution_selector'] ?? []);

        // Extraire les noms et seuils depuis le YAML
        $stayOn15mSpec = $this->parseSpec((array)($selector['stay_on_15m_if'] ?? []));
        $dropTo5mAnySpec = $this->parseSpec((array)($selector['drop_to_5m_if_any'] ?? []));
        $forbidDropAnySpec = $this->parseSpec((array)($selector['forbid_drop_to_5m_if_any'] ?? []));
        
        // Extraire enabled avant de parser allow_1m_only_for
        $allow1mConfig = (array)($selector['allow_1m_only_for'] ?? []);
        $allow1mEnabled = (bool)($allow1mConfig['enabled'] ?? true);
        // Extraire les conditions (support ancien format sans 'conditions' pour compatibilité)
        $allow1mConditions = $allow1mConfig['conditions'] ?? $allow1mConfig;
        // Retirer 'enabled' et 'conditions' de la config avant de parser
        unset($allow1mConditions['enabled']);
        $allow1mOnlyForSpec = $this->parseSpec((array)$allow1mConditions);

        $stayOn15m = array_keys($stayOn15mSpec);
        $dropTo5mAny = array_keys($dropTo5mAnySpec);
        $forbidDropAny = array_keys($forbidDropAnySpec);
        $allow1mOnlyFor = array_keys($allow1mOnlyForSpec);

        // Filtres obligatoires avec éventuels seuils personnalisés
        $filtersMandatorySpec = $this->parseSpec((array)($cfg['filters_mandatory'] ?? []));
        $filtersMandatory = array_keys($filtersMandatorySpec);

        // Injecter les seuils depuis le YAML dans le contexte (niveau debug)
        // 1) Filtres globaux obligatoires
        $context = $this->injectThresholds($context, $filtersMandatorySpec, 'filters_mandatory');
        // 2) Groupes d'exec selector
        $context = $this->injectThresholds($context, $stayOn15mSpec, 'stay_on_15m_if');
        $context = $this->injectThresholds($context, $dropTo5mAnySpec, 'drop_to_5m_if_any');
        $context = $this->injectThresholds($context, $forbidDropAnySpec, 'forbid_drop_to_5m_if_any');
        // Note: allow_1m_only_for contient des booléens, pas des seuils numériques

        // Mandatory filters gate
        $filtersRes = !empty($filtersMandatory) ? $this->registry->evaluate($context, $filtersMandatory) : [];
        $this->logVolumeRatioFilter($filtersRes);
        $filtersPassed = true;
        foreach ($filtersRes as $r) { if (!(bool)($r['passed'] ?? false)) { $filtersPassed = false; break; } }
        if (!$filtersPassed) {
            $this->logger->info('[ExecSelector] filters_mandatory failed', [ 'filters' => $filtersRes ]);
            return new ExecutionDecision('NONE', meta: [ 'filters' => $filtersRes ]);
        }

        $stayRes = !empty($stayOn15m) ? $this->registry->evaluate($context, $stayOn15m) : [];
        $this->logEvaluationResults('stay_on_15m_if', $stayRes, $stayOn15mSpec);
        $stayAll = $this->allPassed($stayRes);
        if ($stayAll) {
            return $this->decision('15m', $context, [
                'stay_on_15m_if' => $stayRes,
                'filters' => $filtersRes,
            ]);
        }

        $forbidRes = !empty($forbidDropAny) ? $this->registry->evaluate($context, $forbidDropAny) : [];
        $this->logEvaluationResults('forbid_drop_to_5m_if_any', $forbidRes, $forbidDropAnySpec);
        $forbidAny = $this->anyPassed($forbidRes);

        $dropRes = !empty($dropTo5mAny) ? $this->registry->evaluate($context, $dropTo5mAny) : [];
        $this->logEvaluationResults('drop_to_5m_if_any', $dropRes, $dropTo5mAnySpec);
        $dropAny = $this->anyPassed($dropRes);

        if ($dropAny && !$forbidAny) {
            return $this->decision('5m', $context, [
                'stay_on_15m_if' => $stayRes,
                'drop_to_5m_if_any' => $dropRes,
                'forbid_drop_to_5m_if_any' => $forbidRes,
                'filters' => $filtersRes,
            ]);
        }

        // Si enabled = false, considérer que allow_1m_only_for retourne toujours true
        if (!$allow1mEnabled) {
            $allow1mAny = true;
            $allow1mRes = ['enabled_override' => ['passed' => true, 'value' => 'disabled', 'meta' => ['reason' => 'allow_1m_only_for.enabled=false']]];
        } else {
            $allow1mRes = !empty($allow1mOnlyFor) ? $this->registry->evaluate($context, $allow1mOnlyFor) : [];
            $allow1mAny = $this->anyPassed($allow1mRes);
        }
        if ($allow1mAny) {
            return $this->decision('1m', $context, [
                'stay_on_15m_if' => $stayRes,
                'drop_to_5m_if_any' => $dropRes,
                'forbid_drop_to_5m_if_any' => $forbidRes,
                'allow_1m_only_for' => $allow1mRes,
                'filters' => $filtersRes,
            ]);
        }

        // Fallback pragmatique: rester 15m
        return $this->decision('15m', $context, [
            'stay_on_15m_if' => $stayRes,
            'drop_to_5m_if_any' => $dropRes,
            'forbid_drop_to_5m_if_any' => $forbidRes,
            'allow_1m_only_for' => $allow1mRes,
            'filters' => $filtersRes,
        ]);
    }

    /** @param array<string,mixed> $meta */
    private function decision(string $tf, array $context, array $meta): ExecutionDecision
    {
        $erm = isset($context['expected_r_multiple']) && \is_numeric($context['expected_r_multiple'])
            ? (float)$context['expected_r_multiple'] : null;
        $w = isset($context['entry_zone_width_pct']) && \is_numeric($context['entry_zone_width_pct'])
            ? (float)$context['entry_zone_width_pct'] : null;
        return new ExecutionDecision($tf, $erm, $w, $meta);
    }

    /** @param array<int,mixed> $spec */
    private function namesFromSpec(array $spec): array
    {
        $out = [];
        foreach ($spec as $item) {
            if (\is_string($item) && $item !== '') { $out[] = $item; continue; }
            if (\is_array($item) && $item !== []) {
                $k = array_key_first($item);
                if (\is_string($k) && $k !== '') { $out[] = $k; }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Parse une spécification YAML et retourne un array associatif [condition_name => threshold_value]
     * @param array<int,mixed> $spec
     * @return array<string,float|int|bool|null> Map condition name => threshold value (ou null si pas de seuil)
     */
    private function parseSpec(array $spec): array
    {
        $out = [];
        foreach ($spec as $item) {
            if (\is_string($item) && $item !== '') {
                $out[$item] = null; // Pas de seuil, utilise le défaut PHP
                continue;
            }
            if (\is_array($item) && $item !== []) {
                $k = array_key_first($item);
                if (\is_string($k) && $k !== '') {
                    $v = $item[$k];
                    // Extraire la valeur si c'est numérique (seuil) ou booléen
                    if (\is_numeric($v)) {
                        $out[$k] = \is_float($v) ? (float)$v : (int)$v;
                    } elseif (\is_bool($v)) {
                        $out[$k] = $v;
                    } else {
                        $out[$k] = null;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Injecte les seuils depuis le YAML dans le contexte pour les conditions qui les acceptent.
     * @param array<string,mixed> $context
     * @param array<string,float|int|bool|null> $spec Map condition name => threshold
     * @param string $groupName Nom du groupe pour le logging
     * @return array<string,mixed> Contexte enrichi avec les seuils
     */
    private function injectThresholds(array $context, array $spec, string $groupName): array
    {
        $injected = [];
        foreach ($spec as $conditionName => $threshold) {
            if ($threshold === null || \is_bool($threshold)) {
                continue; // Pas de seuil numérique ou booléen (géré ailleurs)
            }

            // Construire la clé de contexte pour le seuil : {condition_name}_threshold
            $thresholdKey = $conditionName . '_threshold';
            $context[$thresholdKey] = $threshold;
            $injected[$conditionName] = $threshold;
        }

        if (!empty($injected)) {
            $this->logger->debug('[ExecSelector] Thresholds injected from YAML', [
                'group' => $groupName,
                'thresholds' => $injected,
            ]);
        }

        return $context;
    }

    /**
     * Log les résultats d'évaluation avec les seuils utilisés (niveau debug).
     * @param string $groupName
     * @param array<string,array> $results
     * @param array<string,float|int|bool|null> $spec
     */
    private function logEvaluationResults(string $groupName, array $results, array $spec): void
    {
        if (empty($results)) {
            return;
        }

        $summary = [];
        foreach ($results as $name => $result) {
            $thresholdUsed = $result['threshold'] ?? null;
            $yamlThreshold = $spec[$name] ?? null;
            $usedDefault = ($yamlThreshold === null && $thresholdUsed !== null);

            $summary[$name] = [
                'passed' => $result['passed'] ?? false,
                'value' => $result['value'] ?? null,
                'threshold_used' => $thresholdUsed,
                'threshold_source' => $usedDefault ? 'default_php' : 'yaml',
            ];
        }

        $this->logger->debug('[ExecSelector] Evaluation results', [
            'group' => $groupName,
            'results' => $summary,
        ]);
    }

    /**
     * Log dedicated info for volume_ratio_ok to trace liquidity gating.
     * @param array<string,array> $filtersRes
     */
    private function logVolumeRatioFilter(array $filtersRes): void
    {
        if (!isset($filtersRes['volume_ratio_ok'])) {
            return;
        }

        $result = $filtersRes['volume_ratio_ok'];
        $this->logger->info('[ExecSelector] volume_ratio_ok', [
            'passed' => $result['passed'] ?? null,
            'value' => $result['value'] ?? null,
            'threshold' => $result['threshold'] ?? null,
            'meta' => $result['meta'] ?? [],
        ]);
    }

    /** @param array<string,array> $results */
    private function allPassed(array $results): bool
    {
        if ($results === []) return false;
        foreach ($results as $r) { if (!(bool)($r['passed'] ?? false)) return false; }
        return true;
    }

    /** @param array<string,array> $results */
    private function anyPassed(array $results): bool
    {
        foreach ($results as $r) { if ((bool)($r['passed'] ?? false)) return true; }
        return false;
    }
}
