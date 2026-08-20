<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Audit;

use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\EffectiveTradingConfigResolverInterface;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use Psr\Log\LoggerInterface;

final readonly class PersistentEffectiveTradingConfigResolver implements EffectiveTradingConfigResolverInterface
{
    public function __construct(
        private EffectiveTradingConfigResolver $inner,
        private EffectiveConfigViewerDocumentFactory $documents,
        private EffectiveConfigSnapshotRegistryInterface $registry,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(EffectiveTradingConfigRequest|string $request): EffectiveTradingConfigSnapshot
    {
        $startedAt = hrtime(true);
        $snapshot = $this->inner->resolve($request);
        $document = $this->documents->fromSnapshot($snapshot);
        $this->registry->register($document);
        $identity = $snapshot->request->toArray();

        $this->logger->info('trading_config.effective_snapshot_registered', [
            'snapshot_hash' => $document->snapshotHash(),
            'config_hash' => $document->configHash(),
            'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'mode_id' => $identity['mode_id'],
            'mode_version' => $identity['mode_version'],
            'setup_id' => $identity['setup_id'],
            'setup_version' => $identity['setup_version'],
            'exchange' => $identity['exchange'],
            'environment' => $identity['environment'],
            'side' => $identity['side'],
            'execution_capability' => $identity['execution_capability'] ?? null,
            'layer_count' => count($snapshot->orderedLayers()),
            'redaction_count' => count($document->payload['redacted_paths']),
            'resolver_version' => EffectiveConfigViewerDocumentFactory::RESOLVER_VERSION,
            'validation_status' => 'valid',
            'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000,
        ]);

        return $snapshot;
    }
}
