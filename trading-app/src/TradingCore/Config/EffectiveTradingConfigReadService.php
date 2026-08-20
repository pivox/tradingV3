<?php

declare(strict_types=1);

namespace App\TradingCore\Config;

use App\TradingCore\Config\Audit\EffectiveConfigViewerDocumentFactory;
use App\TradingCore\Config\Audit\EffectiveConfigDiffService;
use App\TradingCore\Config\Audit\EffectiveConfigHash;
use App\TradingCore\Config\Audit\EffectiveConfigSnapshotNotFound;
use App\TradingCore\Config\Audit\EffectiveConfigSnapshotRegistryInterface;

final readonly class EffectiveTradingConfigReadService
{
    public function __construct(
        private EffectiveTradingConfigResolverInterface $resolver,
        private EffectiveConfigViewerDocumentFactory $documents,
        private ?EffectiveConfigSnapshotRegistryInterface $registry = null,
        private ?EffectiveConfigDiffService $diffService = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function describe(EffectiveTradingConfigRequest $request): array
    {
        return $this->documents->fromSnapshot($this->resolver->resolve($request))->payload;
    }

    /** @return array<string,mixed> */
    public function historical(string $snapshotHash): array
    {
        $hash = EffectiveConfigHash::require($snapshotHash);
        $record = $this->registry()->find($hash);
        if ($record === null) {
            throw new EffectiveConfigSnapshotNotFound($hash);
        }

        return ['document_kind' => 'historical_snapshot'] + array_diff_key($record->document, ['document_kind' => true]);
    }

    /** @return list<array<string,mixed>> */
    public function history(string $configHash): array
    {
        $records = $this->registry()->findByConfigHash(EffectiveConfigHash::require($configHash));

        return array_map(
            static fn ($record): array => ['document_kind' => 'historical_snapshot'] + array_diff_key($record->document, ['document_kind' => true]),
            $records,
        );
    }

    /** @return array<string,mixed> */
    public function diff(string $leftSnapshotHash, string $rightSnapshotHash): array
    {
        $leftHash = EffectiveConfigHash::require($leftSnapshotHash);
        $rightHash = EffectiveConfigHash::require($rightSnapshotHash);
        $left = $this->registry()->find($leftHash);
        if ($left === null) {
            throw new EffectiveConfigSnapshotNotFound($leftHash);
        }
        $right = $this->registry()->find($rightHash);
        if ($right === null) {
            throw new EffectiveConfigSnapshotNotFound($rightHash);
        }
        if ($this->diffService === null) {
            throw new \LogicException('effective_config_history_not_configured');
        }

        return $this->diffService->diff($left, $right);
    }

    private function registry(): EffectiveConfigSnapshotRegistryInterface
    {
        return $this->registry ?? throw new \LogicException('effective_config_history_not_configured');
    }
}
