<?php

namespace App\Indicator\ConditionLoader\Cards\Rule;

use App\Indicator\Condition\ConditionResult;
use App\Indicator\ConditionLoader\Cards\AbstractCard;
use App\Indicator\ConditionLoader\ConditionRegistry;
use App\Indicator\ConditionLoader\Cards\Rule\RuleElementCondition;
use App\Indicator\ConditionLoader\Cards\Rule\RuleElementField;
use App\Indicator\ConditionLoader\Cards\Rule\RuleElementOperation;
use App\Indicator\ConditionLoader\Cards\Rule\RuleElementCustomOp;
use App\Indicator\ConditionLoader\Cards\Rule\RuleElement;

class Rule extends AbstractCard
{
    private string $name = '';
    private RuleElementInterface $element;
    private mixed $spec;

    public function fill(array|string $data): static
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Rule definition must be an array');
        }

        $this->name = (string) key($data);
        $this->spec = current($data);
        $this->element = RuleElementFactory::make($data);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getElement(): RuleElementInterface
    {
        return $this->element;
    }

    public function evaluate(array $context): ConditionResult
    {
        return $this->evaluateSpec($this->spec, $context);
    }

    private function evaluateSpec(mixed $spec, array $context): ConditionResult
    {
        if (is_string($spec)) {
            return $this->evaluateCondition($spec, null, $context);
        }

        if (!is_array($spec)) {
            throw new \LogicException(sprintf('Unsupported rule spec for "%s"', $this->name));
        }

        if (isset($spec['any_of'])) {
            return $this->evaluateLogical('any_of', $spec['any_of'], $context);
        }

        if (isset($spec['all_of'])) {
            return $this->evaluateLogical('all_of', $spec['all_of'], $context);
        }

        if (isset($spec['increasing']) || isset($spec['decreasing'])) {
            $key = isset($spec['increasing']) ? 'increasing' : 'decreasing';
            return $this->evaluateTrend($key, $spec[$key], $context);
        }

        if (isset($spec['lt_fields']) || isset($spec['gt_fields'])) {
            return $this->evaluateFieldComparison($spec, $context);
        }

        if (isset($spec['op'])) {
            return $this->evaluateCustomOperation($spec, $context);
        }

        if (count($spec) === 1) {
            $name = (string) key($spec);
            $value = current($spec);
            return $this->evaluateCondition($name, $value, $context);
        }

        throw new \LogicException(sprintf('Unsupported rule spec for "%s"', $this->name));
    }

    private function evaluateLogical(string $type, array $payload, array $context): ConditionResult
    {
        $type = strtolower($type);
        $items = [];
        $passed = $type === 'all_of';

        foreach ($payload as $entry) {
            $result = $this->evaluateSpec($entry, $context);
            $items[] = $result->toArray();

            $isPassed = $result->passed;
            if ($type === 'any_of' && $isPassed) {
                $passed = true;
            }
            if ($type === 'all_of' && !$isPassed) {
                $passed = false;
            }
        }

        if ($type === 'any_of' && $items === []) {
            $passed = false;
        }

        $meta = [
            'rule' => $this->name,
            'type' => $type,
            'items' => $items,
            'item_count' => count($items),
        ];

        return new ConditionResult(
            $this->name,
            $passed,
            null,
            null,
            $meta
        );
    }

    private function evaluateCondition(string $name, mixed $override, array $context): ConditionResult
    {
        $condition = ConditionRegistry::resolveCondition($name);
        if (!$condition) {
            throw new \RuntimeException(sprintf('Condition %s not found for rule %s', $name, $this->name));
        }

        $ctx = $this->applyOverride($context, $override);
        return $condition->evaluate($ctx);
    }

    private function applyOverride(array $context, mixed $override): array
    {
        if ($override === null) {
            return $context;
        }

        $ctx = $context;
        if (is_bool($override)) {
            $ctx['expected'] = $override;
        } elseif (is_numeric($override)) {
            $ctx['threshold'] = (float) $override;
        } elseif (is_string($override)) {
            if (is_numeric($override)) {
                $ctx['threshold'] = (float) $override;
            } else {
                $ctx['value'] = $override;
            }
        }

        return $ctx;
    }

    private function evaluateCustomOperation(array $spec, array $context): ConditionResult
    {
        $left = $this->resolveFieldValue($spec['left'] ?? null, $context);
        $right = $this->resolveFieldValue($spec['right'] ?? null, $context);
        $op = $spec['op'] ?? '>';
        $eps = isset($spec['eps']) && is_numeric($spec['eps']) ? (float) $spec['eps'] : 1.0e-8;

        $passed = false;
        if ($left !== null && $right !== null) {
            $diff = $left - $right;
            $passed = match ($op) {
                '>'  => $diff >  $eps,
                '>=' => $diff >= -$eps,
                '<'  => $diff < -$eps,
                '<=' => $diff <=  $eps,
                default => throw new \LogicException("Unsupported op {$op} in rule {$this->name}"),
            };
        }

        $meta = [
            'rule' => $this->name,
            'type' => 'op',
            'op' => $op,
            'left' => $left,
            'right' => $right,
            'eps' => $eps,
            'missing_data' => ($left === null || $right === null),
        ];

        return new ConditionResult(
            $this->name,
            $passed,
            $left,
            $right,
            $meta
        );
    }

    private function evaluateFieldComparison(array $spec, array $context): ConditionResult
    {
        $op = isset($spec['lt_fields']) ? RuleElementInterface::LT_FIELDS : RuleElementInterface::GT_FIELDS;
        $fields = current($spec);
        if (!is_array($fields) || count($fields) < 2) {
            throw new \LogicException("Invalid field comparison in rule {$this->name}");
        }

        $leftField = $fields[0];
        $rightField = $fields[1];
        $left = $this->resolveFieldValue($leftField, $context);
        $right = $this->resolveFieldValue($rightField, $context);

        $passed = false;
        if ($left !== null && $right !== null) {
            if ($op === RuleElementInterface::LT_FIELDS) {
                $passed = $left < $right;
            } else {
                $passed = $left > $right;
            }
        }

        $meta = [
            'rule' => $this->name,
            'type' => $op,
            'fields' => $fields,
            'left' => $left,
            'right' => $right,
            'missing_data' => ($left === null || $right === null),
        ];

        return new ConditionResult(
            $this->name,
            $passed,
            $left,
            $right,
            $meta
        );
    }

    private function evaluateTrend(string $type, array $payload, array $context): ConditionResult
    {
        $field = $payload['field'] ?? null;
        $series = $this->extractSeries($context, (string) $field);

        $strict = isset($payload['strict']) ? (bool)$payload['strict'] : true;
        $n = max(1, (int)($payload['n'] ?? 1));
        $eps = isset($payload['eps']) && is_numeric($payload['eps']) ? (float)$payload['eps'] : 1.0e-8;

        $meta = [
            'rule' => $this->name,
            'type' => $type,
            'field' => $field,
            'n' => $n,
            'strict' => $strict,
            'eps' => $eps,
        ];

        if ($series === null) {
            return new ConditionResult(
                $this->name,
                false,
                null,
                null,
                $meta + ['missing_data' => true]
            );
        }

        $needed = $n + 1;
        if (count($series) < $needed) {
            return new ConditionResult(
                $this->name,
                false,
                null,
                null,
                $meta + ['missing_data' => true, 'available_points' => count($series)]
            );
        }

        $subset = array_slice($series, 0, $needed);
        $diffs = [];
        $passed = true;

        for ($i = 0; $i < $n; $i++) {
            $current = $subset[$i];
            $next = $subset[$i + 1];
            if ($current === null || $next === null) {
                $passed = false;
                $meta['missing_data'] = true;
                break;
            }
            $diff = $current - $next;
            $diffs[] = $diff;

            if ($type === RuleElementTrend::INCREASING) {
                $passed = $strict ? ($diff > $eps) : ($diff >= -$eps);
            } else {
                $passed = $strict ? ($diff < -$eps) : ($diff <= $eps);
            }

            if (!$passed) {
                break;
            }
        }

        $meta['series_used'] = $subset;
        $meta['diffs'] = $diffs;

        return new ConditionResult(
            $this->name,
            $passed,
            $subset[0] ?? null,
            $subset[$n] ?? null,
            $meta + ['comparisons' => count($diffs)]
        );
    }

    private function extractSeries(array $context, ?string $fieldName): ?array
    {
        if (!$fieldName) {
            return null;
        }

        $seriesKey = sprintf('%s_series', $fieldName);
        if (isset($context[$seriesKey]) && is_array($context[$seriesKey])) {
            return $this->normaliseSeries($context[$seriesKey]);
        }

        if (isset($context['series'][$fieldName]) && is_array($context['series'][$fieldName])) {
            return $this->normaliseSeries($context['series'][$fieldName]);
        }

        return null;
    }

    private function normaliseSeries(array $series): array
    {
        $out = [];
        foreach ($series as $value) {
            $out[] = is_numeric($value) ? (float)$value : null;
        }
        return $out;
    }

    private function resolveFieldValue(mixed $field, array $context): ?float
    {
        if ($field === null) {
            return null;
        }

        if (is_numeric($field)) {
            return (float)$field;
        }

        if (!is_string($field)) {
            return null;
        }

        if (isset($context[$field]) && is_numeric($context[$field])) {
            return (float)$context[$field];
        }

        if (isset($context['macd']) && is_array($context['macd'])) {
            if ($field === 'macd' && isset($context['macd']['macd'])) {
                return (float)$context['macd']['macd'];
            }
            if (isset($context['macd'][$field]) && is_numeric($context['macd'][$field])) {
                return (float)$context['macd'][$field];
            }
        }

        if (isset($context['ema']) && is_array($context['ema']) && preg_match('/^ema_(\\d+)$/', $field, $m)) {
            $period = (int)$m[1];
            if (isset($context['ema'][$period])) {
                return (float)$context['ema'][$period];
            }
        }

        if (str_contains($field, '_')) {
            $parts = explode('_', $field);
            $current = $context;
            foreach ($parts as $part) {
                if (is_array($current) && array_key_exists($part, $current)) {
                    $current = $current[$part];
                } elseif (is_array($current) && array_key_exists((int)$part, $current)) {
                    $current = $current[(int)$part];
                } else {
                    $current = null;
                    break;
                }
            }
            if (is_numeric($current)) {
                return (float)$current;
            }
        }

        return null;
    }
}
