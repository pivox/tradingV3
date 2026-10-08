<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Normalization;

/**
 * Remembers, per ordinal scope, the canonical snapshot of the last serialized
 * scope state that OkxPaperSourceOrdinal::validatedSnapshot() fully validated.
 *
 * Validating one scope is a pure function of its scope key and serialized state.
 * States are keyed by the SHA-256 of their PHP serialization, which encodes every
 * key, value, type and order, so a hit returns exactly the snapshot that a full
 * validation of an identical input produced. Live checkpoints re-validate the
 * whole ordinal state for every event while only one scope changes; the memo
 * limits the expensive PaperMarketEvent validation to changed scopes. It keeps
 * one digest and one snapshot array (shared with the checkpoint that holds it)
 * per finite scope, and is owned by a single checkpoint store instance.
 */
final class OkxPaperSourceOrdinalSnapshotMemo
{
    /** @var array<string, array{digest: string, snapshot: array<string, mixed>}> */
    private array $scopes = [];

    /** @param array<array-key, mixed> $scopeState */
    public static function digest(#[\SensitiveParameter] array $scopeState): string
    {
        return hash('sha256', serialize($scopeState));
    }

    /** @return array<string, mixed>|null */
    public function snapshotScope(string $scope, string $digest): ?array
    {
        $remembered = $this->scopes[$scope] ?? null;
        if ($remembered === null || !hash_equals($remembered['digest'], $digest)) {
            return null;
        }

        return $remembered['snapshot'];
    }

    /** @param array<string, mixed> $snapshot */
    public function remember(string $scope, string $digest, #[\SensitiveParameter] array $snapshot): void
    {
        $this->scopes[$scope] = ['digest' => $digest, 'snapshot' => $snapshot];
    }
}
