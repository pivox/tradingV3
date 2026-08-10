<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Catalog;

use Symfony\Component\Yaml\Yaml;

final class ConditionCatalogLoader
{
    private const TOP_KEYS = ['schema_version', 'catalog_version', 'conditions'];
    private const CONDITION_KEYS = [
        'id', 'implementation', 'metric', 'unit', 'value_type', 'timeframes', 'sides',
        'context_source', 'series_order', 'missing_data_policy', 'parameters', 'provenance', 'status',
    ];
    private const PARAMETER_KEYS = ['type', 'required', 'default', 'min', 'max', 'values'];
    private const TIMEFRAMES = ['global', '4h', '1h', '15m', '5m', '1m'];
    private const SIDES = ['long', 'short'];
    private const PARAMETER_TYPES = ['number', 'integer', 'string', 'boolean'];

    public function loadFile(string $path): ConditionCatalog
    {
        if (!is_file($path)) {
            throw new ConditionCatalogException(sprintf('Condition catalog file "%s" does not exist.', $path));
        }
        $document = Yaml::parseFile($path);
        if (!is_array($document) || array_is_list($document)) {
            throw new ConditionCatalogException('Condition catalog root must be a mapping.');
        }

        return $this->load($document);
    }

    /** @param array<string, mixed> $document */
    public function load(array $document): ConditionCatalog
    {
        $this->exactKeys($document, self::TOP_KEYS, 'catalog');
        if (($document['schema_version'] ?? null) !== 'condition-catalog.v1') {
            throw new ConditionCatalogException('schema_version must be condition-catalog.v1.');
        }
        if (($document['catalog_version'] ?? null) !== '1.0.0') {
            throw new ConditionCatalogException('catalog_version must be the exact supported version 1.0.0.');
        }
        $rows = $document['conditions'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || $rows === []) {
            throw new ConditionCatalogException('conditions must be a non-empty list.');
        }

        $definitions = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new ConditionCatalogException(sprintf('conditions[%d] must be a mapping.', $index));
            }
            $definition = $this->definition($row, $index);
            if (isset($definitions[$definition->id])) {
                throw new ConditionCatalogException(sprintf('Duplicate condition id "%s".', $definition->id));
            }
            $definitions[$definition->id] = $definition;
        }
        ksort($definitions, SORT_STRING);

        return new ConditionCatalog('condition-catalog.v1', '1.0.0', $definitions);
    }

    /** @param array<string, mixed> $row */
    private function definition(array $row, int $index): ConditionDefinition
    {
        $path = sprintf('conditions[%d]', $index);
        $this->exactKeys($row, self::CONDITION_KEYS, $path);
        foreach (['id', 'implementation', 'metric', 'unit', 'value_type', 'context_source', 'series_order', 'missing_data_policy', 'provenance', 'status'] as $key) {
            if (!is_string($row[$key] ?? null) || trim($row[$key]) === '') {
                throw new ConditionCatalogException(sprintf('%s.%s must be a non-empty string.', $path, $key));
            }
        }
        if (preg_match('/^[a-z][a-z0-9_]*$/', $row['id']) !== 1) {
            throw new ConditionCatalogException(sprintf('%s.id is not canonical.', $path));
        }
        $timeframes = $this->stringList($row['timeframes'] ?? null, $path . '.timeframes', self::TIMEFRAMES);
        $sides = $this->stringList($row['sides'] ?? null, $path . '.sides', self::SIDES);
        if ($row['missing_data_policy'] !== 'reject') {
            throw new ConditionCatalogException(sprintf('%s.missing_data_policy must be reject.', $path));
        }
        if (!in_array($row['status'], ['executable', 'blocked'], true)) {
            throw new ConditionCatalogException(sprintf('%s.status must be executable or blocked.', $path));
        }
        $isSeries = $row['value_type'] === 'series<number>';
        if (!in_array($row['value_type'], ['number', 'boolean', 'series<number>'], true)) {
            throw new ConditionCatalogException(sprintf('%s.value_type is unsupported.', $path));
        }
        if (($isSeries && $row['series_order'] !== 'oldest_to_newest') || (!$isSeries && $row['series_order'] !== 'scalar')) {
            throw new ConditionCatalogException(sprintf('%s.series_order must be %s.', $path, $isSeries ? 'oldest_to_newest' : 'scalar'));
        }
        $parameters = $this->parameters($row['parameters'] ?? null, $path . '.parameters');

        return new ConditionDefinition(
            $row['id'], $row['implementation'], $row['metric'], $row['unit'], $row['value_type'],
            $timeframes, $sides, $row['context_source'], $row['series_order'],
            $row['missing_data_policy'], $parameters, $row['provenance'], $row['status'],
        );
    }

    /** @return array<string, ConditionParameterDefinition> */
    private function parameters(mixed $value, string $path): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ConditionCatalogException($path . ' must be a mapping.');
        }
        $result = [];
        foreach ($value as $name => $row) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1 || !is_array($row) || array_is_list($row)) {
                throw new ConditionCatalogException($path . ' contains an invalid parameter definition.');
            }
            $this->allowedKeys($row, self::PARAMETER_KEYS, $path . '.' . $name);
            if (!in_array($row['type'] ?? null, self::PARAMETER_TYPES, true)) {
                throw new ConditionCatalogException(sprintf('Unsupported parameter type for %s.%s.', $path, $name));
            }
            if (!is_bool($row['required'] ?? null)) {
                throw new ConditionCatalogException(sprintf('%s.%s.required must be boolean.', $path, $name));
            }
            $default = $row['default'] ?? null;
            if (!$row['required'] && !array_key_exists('default', $row)) {
                throw new ConditionCatalogException(sprintf('%s.%s requires an explicit default.', $path, $name));
            }
            if (array_key_exists('default', $row) && !$this->matchesType($default, $row['type'])) {
                throw new ConditionCatalogException(sprintf('%s.%s.default has the wrong type.', $path, $name));
            }
            $min = $row['min'] ?? null;
            $max = $row['max'] ?? null;
            foreach (['min' => $min, 'max' => $max] as $bound => $number) {
                if ($number !== null && ((!is_int($number) && !is_float($number)) || !is_finite((float) $number))) {
                    throw new ConditionCatalogException(sprintf('%s.%s.%s must be finite.', $path, $name, $bound));
                }
            }
            if ($min !== null && $max !== null && $min > $max) {
                throw new ConditionCatalogException(sprintf('%s.%s has inverted bounds.', $path, $name));
            }
            $values = $row['values'] ?? [];
            if (!is_array($values) || !array_is_list($values)) {
                throw new ConditionCatalogException(sprintf('%s.%s.values must be a list.', $path, $name));
            }
            $result[$name] = new ConditionParameterDefinition($row['type'], $row['required'], $default, $min, $max, $values);
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'integer' => is_int($value),
            'string' => is_string($value) && trim($value) !== '',
            'boolean' => is_bool($value),
            default => false,
        };
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    private function stringList(mixed $value, string $path, array $allowed): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new ConditionCatalogException($path . ' must be a non-empty list.');
        }
        foreach ($value as $item) {
            if (!is_string($item) || !in_array($item, $allowed, true)) {
                throw new ConditionCatalogException($path . ' contains an unsupported value.');
            }
        }
        if (count(array_unique($value)) !== count($value)) {
            throw new ConditionCatalogException($path . ' must contain unique values.');
        }
        sort($value, SORT_STRING);

        return $value;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $keys
     */
    private function exactKeys(array $value, array $keys, string $path): void
    {
        $this->allowedKeys($value, $keys, $path);
        $missing = array_diff($keys, array_keys($value));
        if ($missing !== []) {
            throw new ConditionCatalogException(sprintf('%s is missing field "%s".', $path, reset($missing)));
        }
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $keys
     */
    private function allowedKeys(array $value, array $keys, string $path): void
    {
        $unknown = array_diff(array_keys($value), $keys);
        if ($unknown !== []) {
            throw new ConditionCatalogException(sprintf('Unknown field "%s" in %s.', reset($unknown), $path));
        }
    }
}
