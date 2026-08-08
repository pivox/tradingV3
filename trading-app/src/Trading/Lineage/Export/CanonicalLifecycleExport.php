<?php

declare(strict_types=1);

namespace App\Trading\Lineage\Export;

final class CanonicalLifecycleExport
{
    private const CONTRACT_FIELDS = [
        'run_id',
        'correlation_run_id',
        'orchestration_run_id',
        'orchestration_set_id',
        'orchestration_dashboard_id',
        'mode_id',
        'mode_version',
        'setup_id',
        'setup_version',
        'config_hash',
        'condition_catalog_hash',
        'side',
        'exchange',
        'market_type',
        'symbol',
        'paper_network',
        'market_data_venue',
        'decision_id',
        'decision_key',
        'intent_id',
        'order_id',
    ];

    private const COMPLETION_FIELDS = ['position_id', 'trade_id'];

    /**
     * @param list<array<string,mixed>> $events
     * @return array{lineage_classification: 'canonical'|'legacy'|'incomplete', identity: array<string,string>|null, reasons: list<string>}
     */
    public function classify(array $events): array
    {
        $modern = array_values(array_filter($events, fn (array $event): bool => $this->hasModernMarker($event)));
        if ($modern === []) {
            return ['lineage_classification' => 'legacy', 'identity' => null, 'reasons' => []];
        }

        $identity = [];
        $reasons = [];
        foreach (self::CONTRACT_FIELDS as $field) {
            $values = $this->values($modern, $field);
            if (count($values) !== 1 || count($modern) !== $this->nonEmptyCount($modern, $field)) {
                $reasons[] = count($values) > 1 ? "conflicting:$field" : "missing:$field";
                continue;
            }
            $identity[$field] = $values[0];
        }

        foreach (self::COMPLETION_FIELDS as $field) {
            $values = $this->values($modern, $field);
            if (count($values) !== 1) {
                $reasons[] = count($values) > 1 ? "conflicting:$field" : "missing:$field";
                continue;
            }
            $identity[$field] = $values[0];
        }

        sort($reasons);
        if ($reasons !== []) {
            return ['lineage_classification' => 'incomplete', 'identity' => null, 'reasons' => $reasons];
        }

        return ['lineage_classification' => 'canonical', 'identity' => $identity, 'reasons' => []];
    }

    /** @param array<string,mixed> $event */
    private function hasModernMarker(array $event): bool
    {
        foreach (['mode_id', 'setup_id', 'config_hash', 'condition_catalog_hash', 'decision_id', 'intent_id'] as $field) {
            if ($this->string($event[$field] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return list<string>
     */
    private function values(array $events, string $field): array
    {
        $values = [];
        foreach ($events as $event) {
            $value = $this->string($event[$field] ?? null);
            if ($value !== null) {
                $values[$value] = true;
            }
        }

        return array_keys($values);
    }

    /** @param list<array<string,mixed>> $events */
    private function nonEmptyCount(array $events, string $field): int
    {
        return count(array_filter($events, fn (array $event): bool => $this->string($event[$field] ?? null) !== null));
    }

    private function string(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
