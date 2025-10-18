<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

/**
 * Helper to evaluate composite condition definitions (any_of/or, all_of/and, nested lists).
 * Provides utilities to extract the atomic condition names referenced inside a definition
 * and to compute pass/fail status for each requirement based on evaluated ConditionResult maps.
 */
final class CompositeConditionEvaluator
{
    /**
     * @param mixed $definition Array of requirements or a single requirement definition.
     * @return string[] Unique atomic condition names referenced inside the definition.
     */
    public static function extractConditionNames(mixed $definition): array
    {
        $names = [];
        self::collectNames($definition, $names);
        return array_values(array_unique($names));
    }

    /**
     * Evaluates a composite definition using previously computed condition results.
     *
     * @param mixed $definition Definition structure (list or single requirement).
     * @param array<string,array> $results Map of condition name => ConditionResult::toArray().
     *
     * @return array{passed: bool, requirements: array<int,array<string,mixed>>}
     */
    public static function evaluateRequirements(mixed $definition, array $results): array
    {
        $items = self::normalizeToList($definition);
        $evaluations = [];
        $allPassed = true;

        foreach ($items as $item) {
            $evaluation = self::evaluateRequirement($item, $results);
            $evaluations[] = $evaluation;
            if ($evaluation['passed'] !== true) {
                $allPassed = false;
            }
        }

        return [
            'passed' => $items === [] ? true : $allPassed,
            'requirements' => $evaluations,
        ];
    }

    /**
     * @param mixed $definition
     * @param string[] $names
     */
    private static function collectNames(mixed $definition, array &$names): void
    {
        if (is_string($definition)) {
            $names[] = $definition;
            return;
        }

        if (!is_array($definition)) {
            return;
        }

        if (array_key_exists('any_of', $definition)) {
            self::collectNames($definition['any_of'], $names);
            return;
        }

        if (array_key_exists('or', $definition)) {
            self::collectNames($definition['or'], $names);
            return;
        }

        if (array_key_exists('all_of', $definition)) {
            self::collectNames($definition['all_of'], $names);
            return;
        }

        if (array_key_exists('and', $definition)) {
            self::collectNames($definition['and'], $names);
            return;
        }

        if (self::isAssoc($definition)) {
            foreach ($definition as $key => $value) {
                if (is_string($key)) {
                    $names[] = $key;
                }
                self::collectNames($value, $names);
            }
            return;
        }

        foreach ($definition as $item) {
            self::collectNames($item, $names);
        }
    }

    /**
     * @param mixed $definition
     * @param array<string,array> $results
     * @return array<string,mixed>
     */
    private static function evaluateRequirement(mixed $definition, array $results): array
    {
        if (is_string($definition)) {
            $single = self::evaluateOption($definition, $results);
            $condition = $single['conditions'][0] ?? $definition;

            return [
                'type' => 'condition',
                'condition' => $condition,
                'passed' => $single['passed'],
                'result' => $single['results'][$condition] ?? null,
            ];
        }

        if (!is_array($definition)) {
            return [
                'type' => 'unknown',
                'definition' => $definition,
                'passed' => false,
            ];
        }

        if (array_key_exists('any_of', $definition) || array_key_exists('or', $definition)) {
            $optionsRaw = $definition[array_key_exists('any_of', $definition) ? 'any_of' : 'or'];
            $options = [];
            $anyPassed = false;
            foreach (self::normalizeToList($optionsRaw) as $option) {
                $evaluated = self::evaluateOption($option, $results);
                $options[] = $evaluated;
                if ($evaluated['passed']) {
                    $anyPassed = true;
                }
            }

            return [
                'type' => 'any_of',
                'passed' => $options === [] ? false : $anyPassed,
                'options' => $options,
            ];
        }

        if (array_key_exists('all_of', $definition) || array_key_exists('and', $definition)) {
            $payload = $definition[array_key_exists('all_of', $definition) ? 'all_of' : 'and'];
            $evaluated = self::evaluateOption($payload, $results);

            return [
                'type' => 'all_of',
                'passed' => $evaluated['passed'],
                'conditions' => $evaluated['conditions'],
                'results' => $evaluated['results'],
                'expected' => $evaluated['expected'] ?? null,
            ];
        }

        if (!self::isAssoc($definition)) {
            $evaluated = self::evaluateOption($definition, $results);

            return [
                'type' => 'all_of',
                'passed' => $evaluated['passed'],
                'conditions' => $evaluated['conditions'],
                'results' => $evaluated['results'],
            ];
        }

        $evaluated = self::evaluateOption($definition, $results);

        return [
            'type' => 'all_of',
            'passed' => $evaluated['passed'],
            'conditions' => $evaluated['conditions'],
            'results' => $evaluated['results'],
            'expected' => $evaluated['expected'] ?? null,
        ];
    }

    /**
     * @param mixed $option
     * @param array<string,array> $results
     * @return array{type:string,conditions:string[],passed:bool,results:array<string,mixed>,expected?:array<string,mixed>|null}
     */
    private static function evaluateOption(mixed $option, array $results): array
    {
        if (is_string($option)) {
            $result = $results[$option] ?? null;
            $passed = ($result['passed'] ?? false) === true;

            return [
                'type' => 'condition',
                'conditions' => [$option],
                'passed' => $passed,
                'results' => [$option => $result],
            ];
        }

        if (!is_array($option)) {
            return [
                'type' => 'unknown',
                'conditions' => [],
                'passed' => false,
                'results' => [],
            ];
        }

        $expected = self::isAssoc($option) ? $option : null;
        $conditions = [];
        $evaluation = [];
        $passed = true;

        if (!self::isAssoc($option)) {
            foreach ($option as $candidate) {
                if (!is_string($candidate)) {
                    continue;
                }
                $conditions[] = $candidate;
                $condResult = $results[$candidate] ?? null;
                $evaluation[$candidate] = $condResult;
                if (($condResult['passed'] ?? false) !== true) {
                    $passed = false;
                }
            }

            return [
                'type' => 'all_of',
                'conditions' => $conditions,
                'passed' => $conditions === [] ? false : $passed,
                'results' => $evaluation,
            ];
        }

        foreach ($option as $candidate => $_expectation) {
            if (!is_string($candidate)) {
                continue;
            }
            $conditions[] = $candidate;
            $condResult = $results[$candidate] ?? null;
            $evaluation[$candidate] = $condResult;
            if (($condResult['passed'] ?? false) !== true) {
                $passed = false;
            }
        }

        return [
            'type' => 'all_of',
            'conditions' => $conditions,
            'passed' => $conditions === [] ? false : $passed,
            'results' => $evaluation,
            'expected' => $expected,
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,mixed>
     */
    private static function normalizeToList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            return [$value];
        }

        return $value;
    }

    private static function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}

