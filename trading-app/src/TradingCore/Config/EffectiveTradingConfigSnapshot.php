<?php

declare(strict_types=1);

namespace App\TradingCore\Config;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;

final readonly class EffectiveTradingConfigSnapshot
{
    /**
     * @param array<string,mixed> $payload
     * @param list<array{type:string,name:string,path:string,required:bool}> $layers
     * @param array<string,array{type:string,name:string,path:string,required:bool}> $provenance
     * @param list<string> $blockers
     */
    public function __construct(
        public EffectiveTradingConfigRequest $request,
        private array $payload,
        public string $configHash,
        public ?string $conditionCatalogHash,
        private array $layers,
        private array $provenance,
        public bool $executable = true,
        public array $blockers = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    /** @return list<array{type:string,name:string,path:string,required:bool}> */
    public function orderedLayers(): array
    {
        return $this->layers;
    }

    /** @return array<string,array{type:string,name:string,path:string,required:bool}> */
    public function provenance(): array
    {
        return $this->provenance;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $snapshot = [
            'request' => $this->request->toArray(),
            'config' => $this->payload,
            'config_hash' => $this->configHash,
            'condition_catalog_hash' => $this->conditionCatalogHash,
            'ordered_layers' => $this->layers,
            'ordered_files' => array_column($this->layers, 'path'),
            'provenance' => $this->provenance,
            'executable' => $this->executable,
            'blockers' => $this->blockers,
        ];
        $snapshot['snapshot_hash'] = CanonicalEffectiveConfigSnapshot::calculateSnapshotHash($snapshot);

        return $snapshot;
    }
}
