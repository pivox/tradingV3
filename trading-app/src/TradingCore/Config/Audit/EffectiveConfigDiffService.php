<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Audit;

final class EffectiveConfigDiffService
{
    /**
     * @return array{
     *   left_snapshot_hash:string,
     *   right_snapshot_hash:string,
     *   summary:array{added:int,removed:int,changed:int,same_but_different_source:int,unchanged:int},
     *   changes:list<array{path:string,classification:string,left:mixed,right:mixed,left_source:mixed,right_source:mixed}>
     * }
     */
    public function diff(EffectiveConfigSnapshotRecord $left, EffectiveConfigSnapshotRecord $right): array
    {
        $leftConfig = $this->requiredMap($left->document, 'config');
        $rightConfig = $this->requiredMap($right->document, 'config');
        $leftValues = $this->flatten($leftConfig);
        $rightValues = $this->flatten($rightConfig);
        $leftProvenance = $this->requiredMap($left->document, 'provenance');
        $rightProvenance = $this->requiredMap($right->document, 'provenance');
        $paths = array_values(array_unique([...array_keys($leftValues), ...array_keys($rightValues)]));
        sort($paths, SORT_STRING);

        $summary = ['added' => 0, 'removed' => 0, 'changed' => 0, 'same_but_different_source' => 0, 'unchanged' => 0];
        $changes = [];
        foreach ($paths as $path) {
            $hasLeft = array_key_exists($path, $leftValues);
            $hasRight = array_key_exists($path, $rightValues);
            $leftValue = $hasLeft ? $leftValues[$path] : null;
            $rightValue = $hasRight ? $rightValues[$path] : null;
            $leftSource = $leftProvenance[$path] ?? null;
            $rightSource = $rightProvenance[$path] ?? null;

            if (!$hasLeft) {
                $classification = 'added';
            } elseif (!$hasRight) {
                $classification = 'removed';
            } elseif (!$this->same($leftValue, $rightValue)) {
                $classification = 'changed';
            } elseif (!$this->same($leftSource, $rightSource)) {
                $classification = 'same_but_different_source';
            } else {
                ++$summary['unchanged'];
                continue;
            }

            ++$summary[$classification];
            $changes[] = [
                'path' => $path,
                'classification' => $classification,
                'left' => $leftValue,
                'right' => $rightValue,
                'left_source' => $leftSource,
                'right_source' => $rightSource,
            ];
        }

        return [
            'left_snapshot_hash' => $this->requiredString($left->document, 'snapshot_hash'),
            'right_snapshot_hash' => $this->requiredString($right->document, 'snapshot_hash'),
            'summary' => $summary,
            'changes' => $changes,
        ];
    }

    /**
     * @param array<string,mixed> $values
     *
     * @return array<string,mixed>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? $key : $prefix . '.' . $key;
            if (is_array($value) && !array_is_list($value)) {
                $flat += $this->flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    private function same(mixed $left, mixed $right): bool
    {
        return hash_equals(
            EffectiveConfigCanonicalJson::encode(['value' => $left]),
            EffectiveConfigCanonicalJson::encode(['value' => $right]),
        );
    }

    /**
     * @param array<string,mixed> $source
     *
     * @return array<string,mixed>
     */
    private function requiredMap(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new \LogicException('effective_config_snapshot_invalid');
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private function requiredString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \LogicException('effective_config_snapshot_invalid');
        }

        return $value;
    }
}
