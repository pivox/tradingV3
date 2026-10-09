<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;
use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZonePolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalStopPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalTargetPolicy;

/** Closed research-only geometry catalog; no effective snapshot is modified. */
final readonly class ResearchVariant
{
    /** @var array<string, array<string, string|float>> */
    private const DIFFS = [
        'baseline' => [],
        'ema20_5m' => ['anchor_source' => 'ema_20'],
        'vwap_15m' => ['anchor_timeframe' => '15m'],
        'ema20_15m' => ['anchor_source' => 'ema_20', 'anchor_timeframe' => '15m'],
        'width_050' => ['zone_atr_multiplier' => 0.50],
        'width_075' => ['zone_atr_multiplier' => 0.75],
        'min_width_001' => ['minimum_half_width_rate' => 0.001],
        'max_width_002' => ['maximum_half_width_rate' => 0.02],
        'stop_125' => ['stop_atr_multiplier' => 1.25],
        'stop_200' => ['stop_atr_multiplier' => 2.0],
        'target_150' => ['target_risk_multiple' => 1.5],
        'target_250' => ['target_risk_multiple' => 2.5],
        'ema20_5m_width_050' => ['anchor_source' => 'ema_20', 'zone_atr_multiplier' => 0.50],
    ];

    /**
     * @param array<string, string|float> $diff
     * @param non-empty-list<CanonicalTargetPolicy> $targets
     */
    private function __construct(
        public string $id,
        public array $diff,
        public string $hash,
        public CanonicalEntryZonePolicy $entryZone,
        public CanonicalStopPolicy $stop,
        public array $targets,
    ) {
    }

    /** @return non-empty-list<self> */
    public static function catalog(CanonicalExecutionPolicy $policy, string $setupHash, string $catalogHash): array
    {
        $variants = [];
        foreach (self::DIFFS as $id => $diff) {
            $variants[] = self::select($id, $diff, $policy, $setupHash, $catalogHash);
        }

        return $variants;
    }

    /** @param array<string, mixed> $diff */
    public static function select(string $id, array $diff, CanonicalExecutionPolicy $policy, string $setupHash, string $catalogHash): self
    {
        self::assertBaseline($policy);
        if (!isset(self::DIFFS[$id]) || self::DIFFS[$id] !== $diff
            || preg_match('/\A(?:sha256:)?[a-f0-9]{64}\z/D', $setupHash) !== 1
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $catalogHash) !== 1) {
            throw new \InvalidArgumentException('research_variant_invalid');
        }
        $baseZone = $policy->entryZone;
        $baseStop = $policy->stop;
        $baseTarget = $policy->targets[0];
        $zone = new CanonicalEntryZonePolicy(
            (string) ($diff['anchor_source'] ?? $baseZone->anchorSource),
            (string) ($diff['anchor_timeframe'] ?? $baseZone->anchorTimeframe),
            $baseZone->atrTimeframe,
            (float) ($diff['zone_atr_multiplier'] ?? $baseZone->atrMultiplier),
            (float) ($diff['minimum_half_width_rate'] ?? $baseZone->minimumHalfWidthRate),
            (float) ($diff['maximum_half_width_rate'] ?? $baseZone->maximumHalfWidthRate),
            $baseZone->asymmetryRate,
            $baseZone->ttlSeconds,
            $baseZone->maximumInputAgeSeconds,
            $baseZone->quantizeOutward,
        );
        $stop = new CanonicalStopPolicy(
            $baseStop->kind, $baseStop->timeframe,
            (float) ($diff['stop_atr_multiplier'] ?? $baseStop->atrMultiplier),
            $baseStop->pivotId, $baseStop->bufferRate,
        );
        $targets = [new CanonicalTargetPolicy(
            $baseTarget->id,
            (float) ($diff['target_risk_multiple'] ?? $baseTarget->riskMultiple),
            $baseTarget->liquidityRole,
        )];
        $hash = CanonicalBacktestRuleEvaluator::canonicalHash([
            'schema_version' => 'research-variant.v1',
            'id' => $id,
            'diff' => $diff,
            'base_setup_hash' => $setupHash,
            'base_config_hash' => $policy->configHash,
            'base_catalog_hash' => $catalogHash,
        ]);

        return new self($id, $diff, $hash, $zone, $stop, $targets);
    }

    private static function assertBaseline(CanonicalExecutionPolicy $policy): void
    {
        $zone = $policy->entryZone;
        $stop = $policy->stop;
        if ($policy->riskPolicy->modeId !== 'day_trading' || $policy->riskPolicy->modeVersion !== '1.1.0'
            || $policy->riskPolicy->setupId !== 'day_trading.trend_continuation.long' || $policy->riskPolicy->setupVersion !== '1.1.0'
            || $policy->riskPolicy->exchange !== 'fake' || $policy->riskPolicy->environment !== 'local' || $policy->riskPolicy->side !== 'long'
            || $zone->anchorSource !== 'vwap' || $zone->anchorTimeframe !== '5m' || $zone->atrTimeframe !== '5m'
            || $zone->atrMultiplier !== 0.30 || $zone->minimumHalfWidthRate !== 0.0005 || $zone->maximumHalfWidthRate !== 0.01
            || $stop->kind !== 'atr' || $stop->timeframe !== '5m' || $stop->atrMultiplier !== 1.5
            || count($policy->targets) !== 1 || $policy->targets[0]->riskMultiple !== 2.0
        ) {
            throw new \InvalidArgumentException('research_baseline_geometry_mismatch');
        }
    }
}
