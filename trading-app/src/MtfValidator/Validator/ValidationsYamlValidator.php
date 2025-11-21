<?php

declare(strict_types=1);

namespace App\MtfValidator\Validator;

use App\Config\MtfValidationConfig;
use App\MtfValidator\ConditionLoader\ConditionRegistry;

/**
 * Validateur technique pour le fichier validations.yaml
 * Détecte les erreurs de configuration : références manquantes, circulaires, syntaxe invalide, types incorrects
 */
final class ValidationsYamlValidator
{
    private array $definedRules = [];
    private array $availableConditions = [];
    private array $visitedRules = []; // Pour détecter les références circulaires

    public function __construct(
        private readonly MtfValidationConfig $config,
        private readonly ConditionRegistry $conditionRegistry
    ) {
    }

    public function validate(): ValidationResult
    {
        $result = new ValidationResult();
        $this->availableConditions = $this->conditionRegistry->names();
        $config = $this->config->getConfig();

        // 1. Valider la structure de base
        $this->validateStructure($config, $result);

        // 2. Charger et valider les règles
        $rules = $this->config->getRules();
        $this->definedRules = array_keys($rules);
        $this->validateRules($rules, $result);

        // 3. Valider execution_selector
        if (isset($config['execution_selector'])) {
            $this->validateExecutionSelector($config['execution_selector'], $result);
        }

        // 4. Valider filters_mandatory
        if (isset($config['filters_mandatory'])) {
            $this->validateFiltersMandatory($config['filters_mandatory'], $result);
        }

        // 5. Valider la section validation (timeframes)
        $validation = $this->config->getValidation();
        if (!empty($validation)) {
            $this->validateValidationSection($validation, $result);
        }

        return $result;
    }

    private function validateStructure(array $config, ValidationResult $result): void
    {
        $requiredKeys = ['rules', 'validation'];
        foreach ($requiredKeys as $key) {
            if (!isset($config[$key])) {
                $result->addError(new ValidationError(
                    'missing_section',
                    sprintf("Section requise '%s' manquante", $key),
                    "mtf_validation.{$key}"
                ));
            }
        }

        if (!isset($config['rules']) || !is_array($config['rules'])) {
            $result->addError(new ValidationError(
                'invalid_type',
                "La section 'rules' doit être un tableau",
                'mtf_validation.rules'
            ));
        }
    }

    private function validateRules(array $rules, ValidationResult $result): void
    {
        foreach ($rules as $ruleName => $ruleSpec) {
            $this->validateRule($ruleName, $ruleSpec, $result, "mtf_validation.rules.{$ruleName}");
        }
    }

    private function validateRule(string $ruleName, mixed $ruleSpec, ValidationResult $result, string $path): void
    {
        if (!is_array($ruleSpec) && !is_string($ruleSpec)) {
            $result->addError(new ValidationError(
                'invalid_type',
                "La règle doit être un tableau ou une chaîne",
                $path,
                $ruleName
            ));
            return;
        }

        // Si c'est une chaîne simple, c'est une référence à une autre règle ou condition
        if (is_string($ruleSpec)) {
            $this->validateRuleReference($ruleSpec, $result, $path, $ruleName);
            return;
        }

        // Valider selon le type de règle
        if (isset($ruleSpec['any_of']) || isset($ruleSpec['all_of'])) {
            $this->validateLogicalRule($ruleName, $ruleSpec, $result, $path);
        } elseif (isset($ruleSpec['op'])) {
            $this->validateCustomOpRule($ruleName, $ruleSpec, $result, $path);
        } elseif (isset($ruleSpec['lt_fields']) || isset($ruleSpec['gt_fields'])) {
            $this->validateFieldComparisonRule($ruleName, $ruleSpec, $result, $path);
        } elseif (isset($ruleSpec['increasing']) || isset($ruleSpec['decreasing'])) {
            $this->validateTrendRule($ruleName, $ruleSpec, $result, $path);
        } elseif (isset($ruleSpec['lt']) || isset($ruleSpec['gt'])) {
            $this->validateOperationRule($ruleName, $ruleSpec, $result, $path);
        } else {
            // Règle simple avec clé => valeur (ex: ['ema_20_minus_ema_50_gt' => -0.0012])
            if (count($ruleSpec) === 1) {
                $key = array_key_first($ruleSpec);
                $value = $ruleSpec[$key];
                if (is_string($key)) {
                    $this->validateRuleReference($key, $result, $path, $ruleName);
                }
                if (!is_numeric($value) && !is_bool($value) && !is_string($value)) {
                    $result->addError(new ValidationError(
                        'invalid_type',
                        "La valeur doit être numérique, booléenne ou chaîne",
                        "{$path}.{$key}",
                        $ruleName,
                        ['value' => $value, 'type' => gettype($value)]
                    ));
                }
            } else {
                // Formats spéciaux de règles (configurations pour conditions PHP)
                $this->validateSpecialRuleFormat($ruleName, $ruleSpec, $result, $path);
            }
        }
    }

    private function validateLogicalRule(string $ruleName, array $spec, ValidationResult $result, string $path): void
    {
        $type = isset($spec['any_of']) ? 'any_of' : 'all_of';
        $items = $spec[$type] ?? [];

        if (!is_array($items)) {
            $result->addError(new ValidationError(
                'invalid_type',
                "{$type} doit être un tableau",
                "{$path}.{$type}",
                $ruleName
            ));
            return;
        }

        if (empty($items)) {
            $result->addError(new ValidationError(
                'empty_array',
                "{$type} ne peut pas être vide",
                "{$path}.{$type}",
                $ruleName
            ));
            return;
        }

        // Détecter les références circulaires
        if (isset($this->visitedRules[$ruleName])) {
            $result->addError(new ValidationError(
                'circular_reference',
                "Référence circulaire détectée",
                $path,
                $ruleName,
                ['visited' => array_keys($this->visitedRules)]
            ));
            return;
        }

        $this->visitedRules[$ruleName] = true;

        foreach ($items as $index => $item) {
            $itemPath = "{$path}.{$type}[{$index}]";
            if (is_string($item)) {
                $this->validateRuleReference($item, $result, $itemPath, $ruleName);
            } elseif (is_array($item)) {
                // Peut être:
                // 1. Une règle avec valeur (ex: ['ema_20_minus_ema_50_gt' => -0.0012])
                // 2. Une structure logique imbriquée (ex: ['all_of' => [...]])
                // 3. Une règle complexe
                if (count($item) === 1) {
                    $key = array_key_first($item);
                    if (is_string($key)) {
                        // Si c'est all_of ou any_of, c'est une structure logique imbriquée
                        if ($key === 'all_of' || $key === 'any_of') {
                            $this->validateLogicalRule($ruleName . '_nested', $item, $result, $itemPath);
                        } else {
                            $this->validateRuleReference($key, $result, $itemPath, $ruleName);
                        }
                    }
                } else {
                    // Règle imbriquée ou structure logique
                    if (isset($item['all_of']) || isset($item['any_of'])) {
                        $this->validateLogicalRule($ruleName . '_nested', $item, $result, $itemPath);
                    } else {
                        $this->validateRule($ruleName . '_nested', $item, $result, $itemPath);
                    }
                }
            }
        }

        unset($this->visitedRules[$ruleName]);
    }

    private function validateCustomOpRule(string $ruleName, array $spec, ValidationResult $result, string $path): void
    {
        // Certaines règles utilisent 'slope_left' au lieu de 'left' (ex: ema200_slope_pos)
        $requiredFields = ['op', 'right'];
        $hasLeft = isset($spec['left']) || isset($spec['slope_left']);
        
        if (!$hasLeft) {
            $result->addError(new ValidationError(
                'missing_field',
                "Champ requis 'left' ou 'slope_left' manquant",
                "{$path}",
                $ruleName
            ));
        }
        
        foreach ($requiredFields as $field) {
            if (!isset($spec[$field])) {
                $result->addError(new ValidationError(
                    'missing_field',
                    "Champ requis '{$field}' manquant",
                    "{$path}.{$field}",
                    $ruleName
                ));
            }
        }

        if (isset($spec['op'])) {
            $validOps = ['>', '>=', '<', '<='];
            if (!in_array($spec['op'], $validOps, true)) {
                $result->addError(new ValidationError(
                    'invalid_operator',
                    sprintf("Opérateur invalide '%s', attendu: %s", $spec['op'], implode(', ', $validOps)),
                    "{$path}.op",
                    $ruleName
                ));
            }
        }

        if (isset($spec['eps']) && (!is_numeric($spec['eps']) || $spec['eps'] < 0)) {
            $result->addError(new ValidationError(
                'invalid_value',
                "eps doit être un nombre positif",
                "{$path}.eps",
                $ruleName
            ));
        }
    }

    private function validateFieldComparisonRule(string $ruleName, array $spec, ValidationResult $result, string $path): void
    {
        $type = isset($spec['lt_fields']) ? 'lt_fields' : 'gt_fields';
        $fields = $spec[$type] ?? [];

        if (!is_array($fields)) {
            $result->addError(new ValidationError(
                'invalid_type',
                "{$type} doit être un tableau",
                "{$path}.{$type}",
                $ruleName
            ));
            return;
        }

        if (count($fields) < 2) {
            $result->addError(new ValidationError(
                'insufficient_fields',
                "{$type} doit contenir au moins 2 champs",
                "{$path}.{$type}",
                $ruleName
            ));
        }

        foreach ($fields as $index => $field) {
            if (!is_string($field)) {
                $result->addError(new ValidationError(
                    'invalid_type',
                    "Le champ à l'index {$index} doit être une chaîne",
                    "{$path}.{$type}[{$index}]",
                    $ruleName
                ));
            }
        }
    }

    private function validateTrendRule(string $ruleName, array $spec, ValidationResult $result, string $path): void
    {
        $type = isset($spec['increasing']) ? 'increasing' : 'decreasing';
        $payload = $spec[$type] ?? [];

        if (!is_array($payload)) {
            $result->addError(new ValidationError(
                'invalid_type',
                "{$type} doit être un tableau",
                "{$path}.{$type}",
                $ruleName
            ));
            return;
        }

        if (!isset($payload['field'])) {
            $result->addError(new ValidationError(
                'missing_field',
                "Champ requis 'field' manquant dans {$type}",
                "{$path}.{$type}.field",
                $ruleName
            ));
        }

        if (isset($payload['n']) && (!is_int($payload['n']) || $payload['n'] < 1)) {
            $result->addError(new ValidationError(
                'invalid_value',
                "n doit être un entier positif",
                "{$path}.{$type}.n",
                $ruleName
            ));
        }

        if (isset($payload['eps']) && (!is_numeric($payload['eps']) || $payload['eps'] < 0)) {
            $result->addError(new ValidationError(
                'invalid_value',
                "eps doit être un nombre positif",
                "{$path}.{$type}.eps",
                $ruleName
            ));
        }
    }

    private function validateOperationRule(string $ruleName, array $spec, ValidationResult $result, string $path): void
    {
        $type = isset($spec['lt']) ? 'lt' : 'gt';
        $value = $spec[$type] ?? null;

        if ($value === null) {
            $result->addError(new ValidationError(
                'missing_value',
                "Valeur manquante pour {$type}",
                "{$path}.{$type}",
                $ruleName
            ));
        } elseif (!is_numeric($value)) {
            $result->addError(new ValidationError(
                'invalid_type',
                "La valeur pour {$type} doit être numérique",
                "{$path}.{$type}",
                $ruleName
            ));
        }
    }

    private function validateRuleReference(string $reference, ValidationResult $result, string $path, ?string $ruleName = null): void
    {
        // Vérifier si c'est une règle définie
        if (in_array($reference, $this->definedRules, true)) {
            return; // OK, référence à une règle
        }

        // Vérifier si c'est une condition PHP disponible
        if (in_array($reference, $this->availableConditions, true)) {
            return; // OK, référence à une condition PHP
        }

        // Référence manquante
        $result->addError(new ValidationError(
            'missing_reference',
            sprintf("Référence '%s' introuvable (ni règle ni condition PHP)", $reference),
            $path,
            $ruleName,
            [
                'reference' => $reference,
                'available_rules' => $this->definedRules,
                'available_conditions' => $this->availableConditions,
            ]
        ));
    }

    private function validateExecutionSelector(array $selector, ValidationResult $result): void
    {
        // Vérifier si le nouveau format per_timeframe est utilisé
        if (isset($selector['per_timeframe'])) {
            $this->validatePerTimeframeFormat($selector['per_timeframe'], $result);
        }

        // Valider les groupes legacy (deprecated mais maintenu pour compatibilité)
        $validGroups = ['stay_on_15m_if', 'drop_to_5m_if_any', 'forbid_drop_to_5m_if_any', 'allow_1m_only_for', 'per_timeframe'];

        foreach ($selector as $groupName => $items) {
            if (!in_array($groupName, $validGroups, true)) {
                $result->addWarning(new ValidationError(
                    'unknown_group',
                    "Groupe inconnu dans execution_selector",
                    "mtf_validation.execution_selector.{$groupName}",
                    null,
                    ['group' => $groupName, 'valid_groups' => $validGroups]
                ));
                continue;
            }

            // per_timeframe est déjà validé séparément
            if ($groupName === 'per_timeframe') {
                continue;
            }

            if (!is_array($items)) {
                $result->addError(new ValidationError(
                    'invalid_type',
                    "Le groupe doit être un tableau",
                    "mtf_validation.execution_selector.{$groupName}",
                    null
                ));
                continue;
            }

            foreach ($items as $index => $item) {
                $itemPath = "mtf_validation.execution_selector.{$groupName}[{$index}]";
                $this->validateExecutionSelectorItem($item, $itemPath, $result);
            }
        }
    }

    /**
     * Valide le format per_timeframe
     */
    private function validatePerTimeframeFormat(array $perTimeframe, ValidationResult $result): void
    {
        $validTimeframes = ['15m', '5m', '1m'];
        $validKeys = ['stay_on_if', 'drop_to_lower_if_any', 'forbid_drop_to_lower_if_any'];

        foreach ($perTimeframe as $tf => $tfConfig) {
            if (!in_array($tf, $validTimeframes, true)) {
                $result->addWarning(new ValidationError(
                    'unknown_timeframe',
                    "Timeframe inconnu dans per_timeframe",
                    "mtf_validation.execution_selector.per_timeframe.{$tf}",
                    null,
                    ['timeframe' => $tf, 'valid_timeframes' => $validTimeframes]
                ));
                continue;
            }

            if (!is_array($tfConfig)) {
                $result->addError(new ValidationError(
                    'invalid_type',
                    "La configuration du timeframe doit être un tableau",
                    "mtf_validation.execution_selector.per_timeframe.{$tf}",
                    null
                ));
                continue;
            }

            foreach ($tfConfig as $key => $value) {
                if (!in_array($key, $validKeys, true)) {
                    $result->addWarning(new ValidationError(
                        'unknown_key',
                        "Clé inconnue dans per_timeframe[{$tf}]",
                        "mtf_validation.execution_selector.per_timeframe.{$tf}.{$key}",
                        null,
                        ['key' => $key, 'valid_keys' => $validKeys]
                    ));
                    continue;
                }

                if (!is_array($value)) {
                    $result->addError(new ValidationError(
                        'invalid_type',
                        "La valeur doit être un tableau de conditions",
                        "mtf_validation.execution_selector.per_timeframe.{$tf}.{$key}",
                        null
                    ));
                    continue;
                }

                foreach ($value as $index => $item) {
                    $itemPath = "mtf_validation.execution_selector.per_timeframe.{$tf}.{$key}[{$index}]";
                    $this->validateExecutionSelectorItem($item, $itemPath, $result);
                }
            }
        }
    }

    private function validateExecutionSelectorItem(mixed $item, string $path, ValidationResult $result): void
    {
        if (is_string($item)) {
            // Simple référence à une condition
            $this->validateRuleReference($item, $result, $path);
        } elseif (is_array($item)) {
            if (empty($item)) {
                $result->addError(new ValidationError(
                    'empty_array',
                    "L'élément ne peut pas être un tableau vide",
                    $path
                ));
                return;
            }

            $key = array_key_first($item);
            $value = $item[$key];

            if (!is_string($key)) {
                $result->addError(new ValidationError(
                    'invalid_key',
                    "La clé doit être une chaîne (nom de condition)",
                    $path
                ));
                return;
            }

            // Valider la référence à la condition
            $this->validateRuleReference($key, $result, $path);

            // Valider la valeur (doit être numérique ou booléenne)
            if (!is_numeric($value) && !is_bool($value)) {
                $result->addError(new ValidationError(
                    'invalid_type',
                    "La valeur doit être numérique ou booléenne",
                    "{$path}.{$key}",
                    null,
                    ['value' => $value, 'type' => gettype($value)]
                ));
            }
        } else {
            $result->addError(new ValidationError(
                'invalid_type',
                "L'élément doit être une chaîne ou un tableau",
                $path,
                null,
                ['type' => gettype($item)]
            ));
        }
    }

    private function validateFiltersMandatory(array $filters, ValidationResult $result): void
    {
        if (!is_array($filters)) {
            $result->addError(new ValidationError(
                'invalid_type',
                "filters_mandatory doit être un tableau",
                'mtf_validation.filters_mandatory'
            ));
            return;
        }

        if (empty($filters)) {
            $result->addWarning(new ValidationError(
                'empty_array',
                "filters_mandatory est vide",
                'mtf_validation.filters_mandatory'
            ));
            return;
        }

        foreach ($filters as $index => $filter) {
            $filterPath = "mtf_validation.filters_mandatory[{$index}]";
            if (is_string($filter)) {
                $this->validateRuleReference($filter, $result, $filterPath);
            } elseif (is_array($filter)) {
                // Format avec clé => valeur (ex: ['condition_name' => value])
                if (count($filter) === 1) {
                    $key = array_key_first($filter);
                    if (is_string($key)) {
                        $this->validateRuleReference($key, $result, $filterPath);
                    }
                } else {
                    $result->addError(new ValidationError(
                        'invalid_format',
                        "Format invalide pour filter_mandatory",
                        $filterPath,
                        null,
                        ['filter' => $filter]
                    ));
                }
            } else {
                $result->addError(new ValidationError(
                    'invalid_type',
                    "Le filtre doit être une chaîne ou un tableau",
                    $filterPath,
                    null,
                    ['type' => gettype($filter)]
                ));
            }
        }
    }

    private function validateValidationSection(array $validation, ValidationResult $result): void
    {
        $validTimeframes = ['4h', '1h', '15m', '5m', '1m'];

        if (isset($validation['timeframe']) && is_array($validation['timeframe'])) {
            foreach ($validation['timeframe'] as $tf => $tfConfig) {
                $tfLower = strtolower((string)$tf);
                if (!in_array($tfLower, $validTimeframes, true)) {
                    $result->addWarning(new ValidationError(
                        'unknown_timeframe',
                        "Timeframe inconnu",
                        "mtf_validation.validation.timeframe.{$tf}",
                        null,
                        ['timeframe' => $tf, 'valid_timeframes' => $validTimeframes]
                    ));
                }

                if (!is_array($tfConfig)) {
                    $result->addError(new ValidationError(
                        'invalid_type',
                        "La configuration du timeframe doit être un tableau",
                        "mtf_validation.validation.timeframe.{$tf}",
                        null
                    ));
                    continue;
                }

                foreach (['long', 'short'] as $side) {
                    if (isset($tfConfig[$side])) {
                        $this->validateTimeframeSide($tfConfig[$side], "mtf_validation.validation.timeframe.{$tf}.{$side}", $result);
                    }
                }
            }
        }
    }

    private function validateSpecialRuleFormat(string $ruleName, array $spec, ValidationResult $result, string $path): void
    {
        // Format: macd_line_cross_up_with_hysteresis / macd_line_cross_down_with_hysteresis
        if (isset($spec['min_gap']) || isset($spec['cool_down_bars']) || isset($spec['require_prev_below']) || isset($spec['require_prev_above'])) {
            $this->validateHysteresisRule($ruleName, $spec, $result, $path);
            return;
        }

        // Format: macd_hist_slope_pos / macd_hist_slope_neg
        if (isset($spec['derivative_gt']) || isset($spec['derivative_lt']) || isset($spec['persist_n'])) {
            $this->validateDerivativeRule($ruleName, $spec, $result, $path);
            return;
        }

        // Format: atr_rel_in_range_15m / atr_rel_in_range_5m
        if (isset($spec['use_atr_tf']) || (isset($spec['min']) && isset($spec['max']))) {
            $this->validateAtrRangeRule($ruleName, $spec, $result, $path);
            return;
        }

        // Format: lev_bounds
        if (isset($spec['field']) && isset($spec['min']) && isset($spec['max'])) {
            $this->validateLevBoundsRule($ruleName, $spec, $result, $path);
            return;
        }

        // Format non reconnu mais peut être valide (géré par des conditions PHP spéciales)
        $result->addWarning(new ValidationError(
            'unknown_rule_format',
            "Format de règle non reconnu, peut être valide mais non vérifié",
            $path,
            $ruleName,
            ['spec' => $spec]
        ));
    }

    private function validateHysteresisRule(string $ruleName, array $spec, ValidationResult $result, string $path): void
    {
        // Champs optionnels mais validés si présents
        if (isset($spec['min_gap']) && !is_numeric($spec['min_gap'])) {
            $result->addError(new ValidationError(
                'invalid_type',
                "min_gap doit être numérique",
                "{$path}.min_gap",
                $ruleName
            ));
        }

        if (isset($spec['cool_down_bars']) && (!is_int($spec['cool_down_bars']) || $spec['cool_down_bars'] < 0)) {
            $result->addError(new ValidationError(
                'invalid_value',
                "cool_down_bars doit être un entier positif",
                "{$path}.cool_down_bars",
                $ruleName
            ));
        }

        if (isset($spec['require_prev_below']) && !is_bool($spec['require_prev_below'])) {
            $result->addError(new ValidationError(
                'invalid_type',
                "require_prev_below doit être booléen",
                "{$path}.require_prev_below",
                $ruleName
            ));
        }

        if (isset($spec['require_prev_above']) && !is_bool($spec['require_prev_above'])) {
            $result->addError(new ValidationError(
                'invalid_type',
                "require_prev_above doit être booléen",
                "{$path}.require_prev_above",
                $ruleName
            ));
        }
    }

    private function validateDerivativeRule(string $ruleName, array $spec, ValidationResult $result, string $path): void
    {
        if (isset($spec['derivative_gt']) && !is_numeric($spec['derivative_gt'])) {
            $result->addError(new ValidationError(
                'invalid_type',
                "derivative_gt doit être numérique",
                "{$path}.derivative_gt",
                $ruleName
            ));
        }

        if (isset($spec['derivative_lt']) && !is_numeric($spec['derivative_lt'])) {
            $result->addError(new ValidationError(
                'invalid_type',
                "derivative_lt doit être numérique",
                "{$path}.derivative_lt",
                $ruleName
            ));
        }

        if (isset($spec['persist_n']) && (!is_int($spec['persist_n']) || $spec['persist_n'] < 1)) {
            $result->addError(new ValidationError(
                'invalid_value',
                "persist_n doit être un entier positif",
                "{$path}.persist_n",
                $ruleName
            ));
        }
    }

    private function validateAtrRangeRule(string $ruleName, array $spec, ValidationResult $result, string $path): void
    {
        if (isset($spec['use_atr_tf'])) {
            if (!is_array($spec['use_atr_tf'])) {
                $result->addError(new ValidationError(
                    'invalid_type',
                    "use_atr_tf doit être un tableau",
                    "{$path}.use_atr_tf",
                    $ruleName
                ));
            } else {
                $validTimeframes = ['1m', '5m', '15m', '1h', '4h'];
                foreach ($spec['use_atr_tf'] as $index => $tf) {
                    if (!is_string($tf) || !in_array(strtolower($tf), $validTimeframes, true)) {
                        $result->addError(new ValidationError(
                            'invalid_value',
                            sprintf("Timeframe invalide dans use_atr_tf[%d]: %s", $index, $tf),
                            "{$path}.use_atr_tf[{$index}]",
                            $ruleName
                        ));
                    }
                }
            }
        }

        if (isset($spec['min']) && !is_numeric($spec['min'])) {
            $result->addError(new ValidationError(
                'invalid_type',
                "min doit être numérique",
                "{$path}.min",
                $ruleName
            ));
        } elseif (isset($spec['min']) && $spec['min'] < 0) {
            $result->addError(new ValidationError(
                'invalid_value',
                "min doit être positif",
                "{$path}.min",
                $ruleName
            ));
        }

        if (isset($spec['max']) && !is_numeric($spec['max'])) {
            $result->addError(new ValidationError(
                'invalid_type',
                "max doit être numérique",
                "{$path}.max",
                $ruleName
            ));
        } elseif (isset($spec['max']) && $spec['max'] < 0) {
            $result->addError(new ValidationError(
                'invalid_value',
                "max doit être positif",
                "{$path}.max",
                $ruleName
            ));
        }

        if (isset($spec['min']) && isset($spec['max']) && is_numeric($spec['min']) && is_numeric($spec['max']) && $spec['min'] > $spec['max']) {
            $result->addError(new ValidationError(
                'invalid_value',
                "min ne peut pas être supérieur à max",
                $path,
                $ruleName
            ));
        }

        if (isset($spec['adapt_with_vol_bucket']) && !is_bool($spec['adapt_with_vol_bucket'])) {
            $result->addError(new ValidationError(
                'invalid_type',
                "adapt_with_vol_bucket doit être booléen",
                "{$path}.adapt_with_vol_bucket",
                $ruleName
            ));
        }
    }

    private function validateLevBoundsRule(string $ruleName, array $spec, ValidationResult $result, string $path): void
    {
        if (!isset($spec['field']) || !is_string($spec['field'])) {
            $result->addError(new ValidationError(
                'missing_field',
                "Champ requis 'field' manquant ou invalide",
                "{$path}.field",
                $ruleName
            ));
        }

        if (!isset($spec['min']) || !is_numeric($spec['min'])) {
            $result->addError(new ValidationError(
                'missing_field',
                "Champ requis 'min' manquant ou invalide",
                "{$path}.min",
                $ruleName
            ));
        } elseif ($spec['min'] < 0) {
            $result->addError(new ValidationError(
                'invalid_value',
                "min doit être positif",
                "{$path}.min",
                $ruleName
            ));
        }

        if (!isset($spec['max']) || !is_numeric($spec['max'])) {
            $result->addError(new ValidationError(
                'missing_field',
                "Champ requis 'max' manquant ou invalide",
                "{$path}.max",
                $ruleName
            ));
        } elseif ($spec['max'] < 0) {
            $result->addError(new ValidationError(
                'invalid_value',
                "max doit être positif",
                "{$path}.max",
                $ruleName
            ));
        }

        if (isset($spec['min']) && isset($spec['max']) && is_numeric($spec['min']) && is_numeric($spec['max']) && $spec['min'] > $spec['max']) {
            $result->addError(new ValidationError(
                'invalid_value',
                "min ne peut pas être supérieur à max",
                $path,
                $ruleName
            ));
        }
    }

    private function validateTimeframeSide(array $sideConfig, string $path, ValidationResult $result): void
    {
        if (!is_array($sideConfig)) {
            $result->addError(new ValidationError(
                'invalid_type',
                "La configuration du side doit être un tableau",
                $path
            ));
            return;
        }

        foreach ($sideConfig as $index => $item) {
            $itemPath = "{$path}[{$index}]";
            if (is_array($item)) {
                if (isset($item['all_of']) || isset($item['any_of'])) {
                    $this->validateLogicalRule('timeframe_side', $item, $result, $itemPath);
                } else {
                    // Peut être une référence directe à une règle (ex: 'price_regime_ok_long')
                    // ou une structure complexe
                    $this->validateRule('timeframe_side', $item, $result, $itemPath);
                }
            } elseif (is_string($item)) {
                // Référence directe à une règle
                $this->validateRuleReference($item, $result, $itemPath);
            } else {
                $result->addError(new ValidationError(
                    'invalid_type',
                    "L'élément doit être un tableau ou une chaîne (référence à une règle)",
                    $itemPath
                ));
            }
        }
    }
}

