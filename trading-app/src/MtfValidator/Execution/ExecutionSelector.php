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

        // Vérifier si execution_selector est vide (pas de per_timeframe, pas de format legacy)
        $perTimeframeCfg = (array)($selector['per_timeframe'] ?? []);
        $hasLegacyConfig = !empty($selector['stay_on_15m_if']) 
            || !empty($selector['drop_to_5m_if_any']) 
            || !empty($selector['forbid_drop_to_5m_if_any'])
            || !empty($selector['allow_1m_only_for']);

        // Si execution_selector est complètement vide, utiliser le timeframe par défaut
        // Mais on évalue quand même les filtres obligatoires avant
        if (empty($perTimeframeCfg) && !$hasLegacyConfig) {
            // Évaluer les filtres obligatoires même si execution_selector est vide
            $filtersMandatorySpec = $this->parseSpec((array)($cfg['filters_mandatory'] ?? []));
            $filtersMandatory = array_keys($filtersMandatorySpec);
            $context = $this->injectThresholds($context, $filtersMandatorySpec, 'filters_mandatory');
            
            $filtersRes = !empty($filtersMandatory) ? $this->registry->evaluate($context, $filtersMandatory) : [];
            $this->logVolumeRatioFilter($filtersRes);
            
            $filtersPassed = true;
            foreach ($filtersRes as $r) {
                if (!(bool)($r['passed'] ?? false)) {
                    $filtersPassed = false;
                    break;
                }
            }
            
            if (!$filtersPassed) {
                $this->logger->info('[ExecSelector] filters_mandatory failed (execution_selector empty)', [
                    'filters' => $filtersRes,
                ]);
                return new ExecutionDecision('NONE', meta: [
                    'filters' => $filtersRes,
                    'reason' => 'filters_mandatory_failed_execution_selector_empty',
                ]);
            }
            
            $defaultTf = $this->getDefaultExecutionTimeframe($cfg);
            $this->logger->info('[ExecSelector] execution_selector is empty, using default timeframe', [
                'default_tf' => $defaultTf,
            ]);
            return $this->decision($defaultTf, $context, [
                'reason' => 'execution_selector_empty_using_default',
                'default_tf' => $defaultTf,
                'filters' => $filtersRes,
            ]);
        }

        // Vérifier si le nouveau format per_timeframe est utilisé
        if (!empty($perTimeframeCfg)) {
            return $this->decidePerTimeframe($context, $selector, $perTimeframeCfg);
        }

        // Fallback vers l'ancien format pour compatibilité
        // Extraire les noms et seuils depuis le YAML (format legacy)
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
        foreach ($filtersRes as $r) {
            if (!(bool)($r['passed'] ?? false)) {
                $filtersPassed = false;
                break;
            }
        }

        if (!$filtersPassed) {
            $this->logger->info('[ExecSelector] filters_mandatory failed', [ 'filters' => $filtersRes ]);
            return new ExecutionDecision('NONE', meta: [ 'filters' => $filtersRes ]);
        }

        // stay_on_15m_if : tous les checks non-missing doivent passer, les missing_data sont ignorés
        $stayRes = !empty($stayOn15m) ? $this->registry->evaluate($context, $stayOn15m) : [];
        $this->logEvaluationResults('stay_on_15m_if', $stayRes, $stayOn15mSpec);
        $stayAll = $this->allPassed($stayRes);

        if ($stayAll) {
            return $this->decision('15m', $context, [
                'stay_on_15m_if' => $stayRes,
                'filters' => $filtersRes,
            ]);
        }

        // forbid_drop_to_5m_if_any : si au moins un check non-missing passe, on interdit la descente
        $forbidRes = !empty($forbidDropAny) ? $this->registry->evaluate($context, $forbidDropAny) : [];
        $this->logEvaluationResults('forbid_drop_to_5m_if_any', $forbidRes, $forbidDropAnySpec);
        $forbidAny = $this->anyPassed($forbidRes);

        // drop_to_5m_if_any : si au moins un check non-missing passe, on descend en 5m
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
            $allow1mRes = [
                'enabled_override' => [
                    'passed' => true,
                    'value' => 'disabled',
                    'meta' => ['reason' => 'allow_1m_only_for.enabled=false'],
                ],
            ];
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

    /**
     * Nouvelle logique avec format per_timeframe
     * @param array<string,mixed> $context
     * @param array<string,mixed> $selector
     * @param array<string,array<string,mixed>> $perTimeframeCfg
     */
    private function decidePerTimeframe(array $context, array $selector, array $perTimeframeCfg): ExecutionDecision
    {
        // Filtres obligatoires avec éventuels seuils personnalisés
        $cfg = $this->registry->getCurrentConfig()?->getConfig() ?? $this->mtfConfig->getConfig();
        $filtersMandatorySpec = $this->parseSpec((array)($cfg['filters_mandatory'] ?? []));
        $filtersMandatory = array_keys($filtersMandatorySpec);

        // Injecter les seuils depuis le YAML dans le contexte
        $context = $this->injectThresholds($context, $filtersMandatorySpec, 'filters_mandatory');

        // Mandatory filters gate
        $filtersRes = !empty($filtersMandatory) ? $this->registry->evaluate($context, $filtersMandatory) : [];
        $this->logVolumeRatioFilter($filtersRes);

        $filtersPassed = true;
        foreach ($filtersRes as $r) {
            if (!(bool)($r['passed'] ?? false)) {
                $filtersPassed = false;
                break;
            }
        }

        if (!$filtersPassed) {
            $this->logger->info('[ExecSelector] filters_mandatory failed', ['filters' => $filtersRes]);
            return new ExecutionDecision('NONE', meta: ['filters' => $filtersRes]);
        }

        // Ordre des timeframes à tester (du plus haut au plus bas)
        $timeframes = ['15m', '5m', '1m'];

        foreach ($timeframes as $tf) {
            $tfConfig = (array)($perTimeframeCfg[$tf] ?? []);
            if (empty($tfConfig)) {
                continue; // Pas de config pour ce TF, passer au suivant
            }

            // stay_on_if : tous les checks non-missing doivent passer
            $stayOnSpec = $this->parseSpec((array)($tfConfig['stay_on_if'] ?? []));
            $stayOn = array_keys($stayOnSpec);
            $context = $this->injectThresholds($context, $stayOnSpec, "per_timeframe[$tf].stay_on_if");

            $stayRes = !empty($stayOn) ? $this->registry->evaluate($context, $stayOn) : [];
            $this->logEvaluationResults("per_timeframe[$tf].stay_on_if", $stayRes, $stayOnSpec);
            $stayAll = $this->allPassed($stayRes);

            if ($stayAll) {
                return $this->decision($tf, $context, [
                    "per_timeframe[$tf].stay_on_if" => $stayRes,
                    'filters' => $filtersRes,
                ]);
            }

            // drop_to_lower_if_any : si au moins un check non-missing passe, on descend
            $dropToLowerSpec = $this->parseSpec((array)($tfConfig['drop_to_lower_if_any'] ?? []));
            $dropToLower = array_keys($dropToLowerSpec);
            $context = $this->injectThresholds($context, $dropToLowerSpec, "per_timeframe[$tf].drop_to_lower_if_any");

            $dropRes = !empty($dropToLower) ? $this->registry->evaluate($context, $dropToLower) : [];
            $this->logEvaluationResults("per_timeframe[$tf].drop_to_lower_if_any", $dropRes, $dropToLowerSpec);
            $dropAny = $this->anyPassed($dropRes);

            // forbid_drop_to_lower_if_any : si au moins un check non-missing passe, on interdit la descente
            $forbidDropSpec = $this->parseSpec((array)($tfConfig['forbid_drop_to_lower_if_any'] ?? []));
            $forbidDrop = array_keys($forbidDropSpec);
            $context = $this->injectThresholds($context, $forbidDropSpec, "per_timeframe[$tf].forbid_drop_to_lower_if_any");

            $forbidRes = !empty($forbidDrop) ? $this->registry->evaluate($context, $forbidDrop) : [];
            $this->logEvaluationResults("per_timeframe[$tf].forbid_drop_to_lower_if_any", $forbidRes, $forbidDropSpec);
            $forbidAny = $this->anyPassed($forbidRes);

            // Si drop_to_lower est vrai et forbid_drop est faux, on descend au TF suivant
            if ($dropAny && !$forbidAny) {
                // Continuer vers le TF suivant (sera traité dans la prochaine itération)
                continue;
            }

            // Si on arrive ici, on ne peut ni rester ni descendre depuis ce TF
            // On continue vers le TF suivant
        }

        // Fallback : si aucun TF n'a été sélectionné, utiliser le premier TF d'exécution disponible
        // ou retourner NONE si aucun TF n'est valide
        $allow1mConfig = (array)($selector['allow_1m_only_for'] ?? []);
        $allow1mEnabled = (bool)($allow1mConfig['enabled'] ?? true);

        if ($allow1mEnabled) {
            // Essayer 1m comme dernier recours
            $allow1mConditions = $allow1mConfig['conditions'] ?? $allow1mConfig;
            unset($allow1mConditions['enabled']);
            $allow1mOnlyForSpec = $this->parseSpec((array)$allow1mConditions);
            $allow1mOnlyFor = array_keys($allow1mOnlyForSpec);
            $allow1mRes = !empty($allow1mOnlyFor) ? $this->registry->evaluate($context, $allow1mOnlyFor) : [];
            $allow1mAny = $this->anyPassed($allow1mRes);

            if ($allow1mAny) {
                return $this->decision('1m', $context, [
                    'allow_1m_only_for' => $allow1mRes,
                    'filters' => $filtersRes,
                ]);
            }
        }

        // Fallback final : rester sur le premier TF d'exécution (15m par défaut)
        return $this->decision('15m', $context, [
            'filters' => $filtersRes,
            'reason' => 'no_timeframe_selected_fallback',
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

    /**
     * Tous les checks non-missing doivent passer pour que le groupe soit vrai.
     * Les conditions avec meta.missing_data=true sont ignorées.
     *
     * @param array<string,array> $results
     */
    private function allPassed(array $results): bool
    {
        if ($results === []) {
            return false;
        }

        $hasNonMissing = false;

        foreach ($results as $r) {
            $meta = $r['meta'] ?? [];
            $isMissing = (bool)($meta['missing_data'] ?? false);

            if ($isMissing) {
                // On ignore complètement cette condition dans le groupe
                continue;
            }

            $hasNonMissing = true;

            if (!(bool)($r['passed'] ?? false)) {
                // Une condition réelle (non-missing) a échoué -> le groupe échoue
                return false;
            }
        }

        // Si toutes les conditions étaient en missing_data, le groupe ne décide rien -> false
        return $hasNonMissing;
    }

    /**
     * Le groupe est vrai dès qu'un check non-missing passe.
     * Les conditions avec meta.missing_data=true sont ignorées.
     *
     * @param array<string,array> $results
     */
    private function anyPassed(array $results): bool
    {
        foreach ($results as $r) {
            $meta = $r['meta'] ?? [];
            $isMissing = (bool)($meta['missing_data'] ?? false);

            if ($isMissing) {
                // On ignore complètement cette condition
                continue;
            }

            if ((bool)($r['passed'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Récupère le timeframe d'exécution par défaut depuis la config
     * @param array<string,mixed> $cfg Configuration complète
     * @return string Timeframe par défaut (15m, 5m, 1m)
     */
    private function getDefaultExecutionTimeframe(array $cfg): string
    {
        // Essayer execution_timeframes (premier élément)
        $execTimeframes = (array)($cfg['execution_timeframes'] ?? []);
        if (!empty($execTimeframes)) {
            $firstTf = strtolower((string)($execTimeframes[0] ?? ''));
            if (in_array($firstTf, ['15m', '5m', '1m'], true)) {
                return $firstTf;
            }
        }

        // Fallback vers execution_timeframe_default
        $defaultTf = strtolower((string)($cfg['execution_timeframe_default'] ?? '5m'));
        if (in_array($defaultTf, ['15m', '5m', '1m'], true)) {
            return $defaultTf;
        }

        // Fallback final
        return '5m';
    }
}
