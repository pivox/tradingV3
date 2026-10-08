<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Lineage\LineageContext;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;

final class PaperCanonicalStrategyEvidenceProvider implements PaperCanonicalStrategyEvidenceProviderInterface
{
    /** @var array<string, list<string>> */
    private const TIMEFRAMES = [
        'day_trading' => ['1m', '5m', '15m', '1h', '4h'],
        'scalping' => ['1m', '5m', '15m', '1h'],
        'micro_scalping' => ['1m', '5m'],
    ];

    /** RFC 4122 name space of Paper canonical decision ids: uuid5(NAMESPACE_URL, 'urn:tradingv3:paper:canonical-decision'). */
    private const DECISION_ID_NAMESPACE = 'ca0e15d6-b3d4-59e5-9ab2-e87035e41756';

    /** @var array<string, \App\TradingCore\Config\EffectiveTradingConfigSnapshot> */
    private array $snapshots = [];

    public function __construct(
        private readonly EffectiveTradingConfigResolver $configs,
        private readonly PaperCanonicalStrategyEvidenceSourceInterface $source,
    ) {
    }

    public function evidenceFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $event,
        string $sourceDatasetId,
        string $sourceEventsFileSha256,
        string $sourceBuildVersion,
    ): ?PaperCanonicalStrategyEvidence {
        $identity = $cell->modernIdentity
            ?? throw new \LogicException('paper_canonical_strategy_cell_identity_missing');
        if ($event->sourceNetwork !== $cell->network || $event->sourceVenue !== $cell->marketDataVenue) {
            throw new \LogicException('paper_canonical_strategy_market_scope_mismatch');
        }
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/D', $sourceDatasetId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $sourceEventsFileSha256) !== 1
            || $sourceBuildVersion === ''
            || trim($sourceBuildVersion) !== $sourceBuildVersion
        ) {
            throw new \LogicException('paper_canonical_strategy_dataset_mismatch');
        }
        $request = new EffectiveTradingConfigRequest(
            $identity->modeId,
            $identity->modeVersion,
            $identity->setupId,
            $identity->setupVersion,
            $cell->marketDataVenue->value,
            $cell->network->value,
            $identity->side,
            ShadowExecutionCapability::Paper,
        );
        $snapshot = $this->snapshots[$cell->id] ??= $this->configs->resolve($request);
        if (!hash_equals($identity->configHash, $snapshot->configHash)
            || !is_string($snapshot->conditionCatalogHash)
            || !hash_equals($identity->conditionCatalogHash, $snapshot->conditionCatalogHash)
        ) {
            throw new \LogicException('paper_canonical_strategy_config_identity_mismatch');
        }
        if (!$this->isExecutionTrigger($snapshot, $event)) {
            throw PaperCanonicalStrategyEvidenceUnavailable::indicatorProjection();
        }
        $timeframes = self::TIMEFRAMES[$identity->modeId] ?? null;
        if ($timeframes === null) {
            throw new \LogicException('paper_canonical_strategy_timeframes_unsupported');
        }
        $digest = hash('sha256', CanonicalJson::encode([
            'cell_id' => $cell->id,
            'event_id' => $event->eventId,
            'dataset_id' => $sourceDatasetId,
            'events_file_sha256' => $sourceEventsFileSha256,
            'source_build_version' => $sourceBuildVersion,
            'config_hash' => $snapshot->configHash,
        ]));
        $decisionKey = 'paper:' . $digest;
        $inputs = $this->source->collect(
            $cell,
            $event,
            $snapshot,
            $timeframes,
            $sourceBuildVersion,
            $sourceEventsFileSha256,
            'paper-indicators:' . $digest,
        );
        if ($inputs === null) {
            return null;
        }
        $snapshotData = $snapshot->toArray();
        $lineage = LineageContext::fromOrchestratorPayload([
            'origin' => LineageContext::ORIGIN_ORCHESTRATOR,
            'orchestration_run_id' => $cell->runId,
            'correlation_run_id' => $cell->runId,
            'orchestration_set_id' => 'paper-set:' . substr($digest, 0, 48),
            // One "dashboard" per Paper run (#132 m): position_trade_analysis_v2 requires a dashboard
            // id on a canonical lifecycle. The run id is not hexadecimal, unlike a digest that the
            // redactor heuristic could refuse at random (#132 e).
            'orchestration_dashboard_id' => $cell->runId,
            'mode_id' => $identity->modeId,
            'mode_version' => $identity->modeVersion,
            'setup_id' => $identity->setupId,
            'setup_version' => $identity->setupVersion,
            'config_hash' => $snapshot->configHash,
            'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'side' => strtoupper($identity->side),
            'exchange' => $cell->marketDataVenue->value,
            'environment' => $cell->network->value,
            'market_type' => 'perpetual',
            'symbol' => $event->symbol,
            'decision_key' => $decisionKey,
            'decision_id' => self::decisionId($digest),
            'dry_run' => true,
            'effective_config_reference' => 'effective-config-snapshot:' . $snapshotData['snapshot_hash'],
            'effective_config_snapshot' => $snapshotData,
        ]);
        $costs = $inputs->orderPlanRequest->costs;

        return new PaperCanonicalStrategyEvidence(
            $request,
            $lineage,
            $inputs->indicatorProjection,
            $sourceDatasetId,
            $sourceEventsFileSha256,
            $sourceBuildVersion,
            $inputs->orderPlanRequest,
            $inputs->portfolioSnapshot->scope,
            $inputs->portfolioSnapshot,
            $decisionKey,
            $inputs->orderBook->spreadBps,
            $costs->entrySlippageRate === null ? null : $costs->entrySlippageRate * 10_000.0,
            $inputs->orderBook,
        );
    }

    /**
     * Deterministic decision id (#132 decision d): a name-based UUID (version 5) of the decision
     * digest, i.e. of the cell, the source event, the dataset and the configuration. A replay,
     * a resume or another instance of the same cell always derives the same id.
     */
    private static function decisionId(string $digest): string
    {
        $namespace = hex2bin(str_replace('-', '', self::DECISION_ID_NAMESPACE));
        $hash = sha1($namespace . 'paper-canonical-decision:' . $digest);

        return sprintf(
            '%s-%s-5%s-%02x%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            (hexdec(substr($hash, 16, 2)) & 0x3f) | 0x80,
            substr($hash, 18, 2),
            substr($hash, 20, 12),
        );
    }

    private function isExecutionTrigger(
        \App\TradingCore\Config\EffectiveTradingConfigSnapshot $snapshot,
        PaperMarketEvent $event,
    ): bool {
        $config = $snapshot->payload();
        $execution = $config['setup']['ast']['execution']['execution_timeframe'] ?? null;
        $timeframe = is_array($execution) && ($execution['state'] ?? null) === 'defined'
            ? ($execution['value'] ?? null)
            : null;
        if (!is_string($timeframe) || !in_array($timeframe, ['1m', '5m', '15m', '1h', '4h'], true)) {
            throw new \LogicException('paper_canonical_strategy_execution_timeframe_invalid');
        }
        $channel = match ($timeframe) {
            '1m' => \App\Trading\Paper\MarketData\PaperMarketDataChannel::CANDLE_1M,
            '5m' => \App\Trading\Paper\MarketData\PaperMarketDataChannel::CANDLE_5M,
            '15m' => \App\Trading\Paper\MarketData\PaperMarketDataChannel::CANDLE_15M,
            '1h' => \App\Trading\Paper\MarketData\PaperMarketDataChannel::CANDLE_1H,
            default => null,
        };
        $declared = $event->payload['interval'] ?? $event->payload['bar'] ?? null;

        return $channel !== null
            && $event->channel === $channel
            && ($event->payload['confirmed'] ?? null) === true
            && is_string($declared)
            && strtolower($declared) === $timeframe;
    }
}
