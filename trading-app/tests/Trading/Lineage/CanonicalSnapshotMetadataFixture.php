<?php

declare(strict_types=1);

namespace App\Tests\Trading\Lineage;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;

final class CanonicalSnapshotMetadataFixture
{
    /**
     * @param array<string,mixed> $snapshot
     *
     * @return array<string,mixed>
     */
    public static function enrich(array $snapshot): array
    {
        $layers = array_map(
            static fn (string $type): array => ['type' => $type, 'name' => $type, 'path' => '/' . $type . '.yaml', 'required' => true],
            ['base', 'mode', 'setup', 'exchange', 'mode_exchange', 'environment'],
        );
        $snapshot += [
            'ordered_layers' => $layers,
            'ordered_files' => array_column($layers, 'path'),
            'provenance' => ['fixture.value' => $layers[2]],
        ];
        $snapshot['snapshot_hash'] = CanonicalEffectiveConfigSnapshot::calculateSnapshotHash($snapshot);

        return $snapshot;
    }
}
