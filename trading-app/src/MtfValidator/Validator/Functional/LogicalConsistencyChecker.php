<?php

declare(strict_types=1);

namespace App\MtfValidator\Validator\Functional;

use App\Config\MtfValidationConfig;

/**
 * Vérifie la cohérence logique entre les règles
 */
final class LogicalConsistencyChecker
{
    private array $rules = [];
    private array $ruleDependencies = [];

    public function __construct(
        private readonly MtfValidationConfig $config
    ) {
        $this->rules = $this->config->getRules();
        $this->buildDependencyGraph();
    }

    /**
     * @return LogicalConsistencyIssue[]
     */
    public function check(): array
    {
        $issues = [];

        $issues = array_merge($issues, $this->checkContradictions());
        $issues = array_merge($issues, $this->checkRedundancies());
        $issues = array_merge($issues, $this->checkCircularDependencies());
        $issues = array_merge($issues, $this->checkUnreachableRules());
        $issues = array_merge($issues, $this->checkConflictingThresholds());

        return $issues;
    }

    /**
     * Construit le graphe de dépendances entre les règles
     */
    private function buildDependencyGraph(): void
    {
        foreach ($this->rules as $ruleName => $ruleSpec) {
            $this->ruleDependencies[$ruleName] = $this->extractDependencies($ruleSpec);
        }
    }

    /**
     * Extrait les dépendances d'une règle
     */
    private function extractDependencies(mixed $spec): array
    {
        $deps = [];

        if (is_string($spec)) {
            $deps[] = $spec;
        } elseif (is_array($spec)) {
            if (isset($spec['any_of']) || isset($spec['all_of'])) {
                $type = isset($spec['any_of']) ? 'any_of' : 'all_of';
                foreach ($spec[$type] as $item) {
                    $deps = array_merge($deps, $this->extractDependencies($item));
                }
            } elseif (count($spec) === 1) {
                $key = array_key_first($spec);
                if (is_string($key)) {
                    $deps[] = $key;
                }
            }
        }

        return array_unique($deps);
    }

    /**
     * Vérifie les contradictions (règles qui s'excluent mutuellement)
     */
    private function checkContradictions(): array
    {
        $issues = [];

        // Exemple: rsi_lt_70 et rsi_gt_70 ne peuvent pas être vraies en même temps
        $contradictoryPairs = [
            ['rsi_lt_70', 'rsi_gt_softfloor'], // Si RSI < 70 et RSI > 30, pas de contradiction directe
            ['ema_20_gt_50', 'ema_20_lt_50'],
            ['close_above_ema_200', 'close_below_ema_200'],
        ];

        foreach ($contradictoryPairs as [$rule1, $rule2]) {
            if (isset($this->rules[$rule1]) && isset($this->rules[$rule2])) {
                // Vérifier si elles sont utilisées dans le même all_of
                if ($this->areUsedTogether($rule1, $rule2, 'all_of')) {
                    $issues[] = new LogicalConsistencyIssue(
                        'contradiction',
                        'high',
                        "Règles contradictoires utilisées ensemble dans all_of: {$rule1} et {$rule2}",
                        [$rule1, $rule2],
                        ['type' => 'mutually_exclusive_in_all_of']
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * Vérifie les redondances (règles équivalentes ou qui se chevauchent)
     */
    private function checkRedundancies(): array
    {
        $issues = [];

        // Exemple: ema20_over_50_with_tolerance inclut déjà ema_20_gt_50
        $redundantPairs = [
            ['ema20_over_50_with_tolerance', 'ema_20_gt_50'],
            ['ema_above_200_with_tolerance', 'close_above_ema_200'],
        ];

        foreach ($redundantPairs as [$rule1, $rule2]) {
            if (isset($this->rules[$rule1]) && isset($this->rules[$rule2])) {
                $deps1 = $this->ruleDependencies[$rule1] ?? [];
                if (in_array($rule2, $deps1, true)) {
                    // Vérifier si elles sont utilisées ensemble dans all_of
                    if ($this->areUsedTogether($rule1, $rule2, 'all_of')) {
                        $issues[] = new LogicalConsistencyIssue(
                            'redundancy',
                            'medium',
                            "Règle redondante: {$rule1} inclut déjà {$rule2}",
                            [$rule1, $rule2],
                            ['type' => 'redundant_in_all_of']
                        );
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Vérifie les dépendances circulaires (déjà fait dans le validateur technique, mais on peut vérifier la logique)
     */
    private function checkCircularDependencies(): array
    {
        $issues = [];

        foreach ($this->ruleDependencies as $ruleName => $deps) {
            foreach ($deps as $dep) {
                if (isset($this->ruleDependencies[$dep]) && in_array($ruleName, $this->ruleDependencies[$dep], true)) {
                    $issues[] = new LogicalConsistencyIssue(
                        'circular_dependency',
                        'high',
                        "Dépendance circulaire détectée: {$ruleName} <-> {$dep}",
                        [$ruleName, $dep],
                        ['type' => 'mutual_dependency']
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * Vérifie les règles inaccessibles (définies mais jamais utilisées)
     */
    private function checkUnreachableRules(): array
    {
        $issues = [];
        $validation = $this->config->getValidation();
        $executionSelector = $this->config->getConfig()['execution_selector'] ?? [];
        $filtersMandatory = $this->config->getConfig()['filters_mandatory'] ?? [];

        $usedRules = [];

        // Collecter les règles utilisées dans la validation
        if (isset($validation['timeframe'])) {
            foreach ($validation['timeframe'] as $tfConfig) {
                foreach (['long', 'short'] as $side) {
                    if (isset($tfConfig[$side])) {
                        $usedRules = array_merge($usedRules, $this->extractUsedRules($tfConfig[$side]));
                    }
                }
            }
        }

        // Collecter les règles utilisées dans execution_selector
        foreach ($executionSelector as $group) {
            if (is_array($group)) {
                foreach ($group as $item) {
                    if (is_string($item)) {
                        $usedRules[] = $item;
                    } elseif (is_array($item)) {
                        $key = array_key_first($item);
                        if (is_string($key)) {
                            $usedRules[] = $key;
                        }
                    }
                }
            }
        }

        // Collecter les règles utilisées dans filters_mandatory
        foreach ($filtersMandatory as $filter) {
            if (is_string($filter)) {
                $usedRules[] = $filter;
            } elseif (is_array($filter)) {
                $key = array_key_first($filter);
                if (is_string($key)) {
                    $usedRules[] = $key;
                }
            }
        }

        $usedRules = array_unique($usedRules);

        // Trouver les règles définies mais non utilisées
        foreach ($this->rules as $ruleName => $ruleSpec) {
            if (!in_array($ruleName, $usedRules, true)) {
                // Vérifier si la règle est utilisée indirectement (via dépendances)
                $isUsedIndirectly = false;
                foreach ($usedRules as $usedRule) {
                    if (isset($this->ruleDependencies[$usedRule]) && in_array($ruleName, $this->ruleDependencies[$usedRule], true)) {
                        $isUsedIndirectly = true;
                        break;
                    }
                }

                if (!$isUsedIndirectly) {
                    $issues[] = new LogicalConsistencyIssue(
                        'unreachable_rule',
                        'low',
                        "Règle définie mais jamais utilisée: {$ruleName}",
                        [$ruleName],
                        ['type' => 'unused_rule']
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * Extrait les règles utilisées dans une structure de validation
     */
    private function extractUsedRules(mixed $config): array
    {
        $rules = [];

        if (is_string($config)) {
            $rules[] = $config;
        } elseif (is_array($config)) {
            foreach ($config as $item) {
                if (is_string($item)) {
                    $rules[] = $item;
                } elseif (is_array($item)) {
                    if (isset($item['all_of']) || isset($item['any_of'])) {
                        $type = isset($item['all_of']) ? 'all_of' : 'any_of';
                        foreach ($item[$type] as $subItem) {
                            $rules = array_merge($rules, $this->extractUsedRules($subItem));
                        }
                    } else {
                        $key = array_key_first($item);
                        if (is_string($key)) {
                            $rules[] = $key;
                        }
                    }
                }
            }
        }

        return $rules;
    }

    /**
     * Vérifie les seuils conflictuels (ex: min > max)
     */
    private function checkConflictingThresholds(): array
    {
        $issues = [];

        // Vérifier les règles avec min/max
        foreach ($this->rules as $ruleName => $ruleSpec) {
            if (is_array($ruleSpec)) {
                if (isset($ruleSpec['min']) && isset($ruleSpec['max'])) {
                    if (is_numeric($ruleSpec['min']) && is_numeric($ruleSpec['max']) && $ruleSpec['min'] > $ruleSpec['max']) {
                        $issues[] = new LogicalConsistencyIssue(
                            'conflicting_threshold',
                            'high',
                            "Seuils conflictuels dans {$ruleName}: min ({$ruleSpec['min']}) > max ({$ruleSpec['max']})",
                            [$ruleName],
                            ['min' => $ruleSpec['min'], 'max' => $ruleSpec['max']]
                        );
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Vérifie si deux règles sont utilisées ensemble dans un all_of
     */
    private function areUsedTogether(string $rule1, string $rule2, string $type): bool
    {
        $validation = $this->config->getValidation();

        if (!isset($validation['timeframe'])) {
            return false;
        }

        foreach ($validation['timeframe'] as $tfConfig) {
            foreach (['long', 'short'] as $side) {
                if (isset($tfConfig[$side])) {
                    if ($this->areRulesTogetherInStructure($tfConfig[$side], $rule1, $rule2, $type)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Vérifie récursivement si deux règles sont ensemble dans une structure
     */
    private function areRulesTogetherInStructure(mixed $structure, string $rule1, string $rule2, string $type): bool
    {
        if (!is_array($structure)) {
            return false;
        }

        foreach ($structure as $item) {
            if (is_array($item) && isset($item[$type])) {
                $items = $item[$type];
                $hasRule1 = false;
                $hasRule2 = false;

                foreach ($items as $subItem) {
                    $rules = $this->extractUsedRules($subItem);
                    if (in_array($rule1, $rules, true)) {
                        $hasRule1 = true;
                    }
                    if (in_array($rule2, $rules, true)) {
                        $hasRule2 = true;
                    }
                }

                if ($hasRule1 && $hasRule2) {
                    return true;
                }
            }
        }

        return false;
    }
}


