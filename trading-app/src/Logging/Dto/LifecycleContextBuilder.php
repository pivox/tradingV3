<?php

declare(strict_types=1);

namespace App\Logging\Dto;

/**
 * Accumule toutes les métriques destinées à l'extra JSON des TradeLifecycleEvent.
 */
final class LifecycleContextBuilder
{
    /** @var array<string,mixed> */
    private array $data;

    public function __construct(
        private readonly string $symbol,
        private readonly ?string $workerId = null,
        private readonly ?string $appVersion = null,
    ) {
        $this->data = [
            'symbol' => strtoupper($symbol),
        ];
        if ($workerId !== null && $workerId !== '') {
            $this->data['worker_id'] = $workerId;
        }
        if ($appVersion !== null && $appVersion !== '') {
            $this->data['version'] = $appVersion;
        }
    }

    public function withDecisionKey(?string $decisionKey): self
    {
        return $this->set('decision_key', $decisionKey);
    }

    public function withProfile(?string $profile): self
    {
        return $this->set('profile', $profile);
    }

    public function withMtfContext(?string $level, array $context = [], ?string $failTf = null): self
    {
        if ($level !== null && $level !== '') {
            $this->data['mtf_level'] = strtolower($level);
        }

        if ($context !== []) {
            $normalized = array_values(array_unique(array_filter(
                array_map(
                    static fn($value) => \is_string($value) ? strtolower($value) : null,
                    $context
                )
            )));
            if ($normalized !== []) {
                $this->data['mtf_context'] = $normalized;
            }
        }

        if ($failTf !== null && $failTf !== '') {
            $this->data['mtf_fail_tf'] = strtolower($failTf);
        }

        return $this;
    }

    public function withSelectorDecision(?float $expectedRMultiple, ?float $entryZoneWidthPct = null): self
    {
        $this->set('expected_r_multiple', $expectedRMultiple);

        if ($entryZoneWidthPct !== null) {
            $this->data['entry_zone_width_pct_selector'] = $entryZoneWidthPct;
        }

        return $this;
    }

    /**
     * @param array<string,mixed> $metrics
     */
    public function withEntryZone(array $metrics): self
    {
        $this->set('entry_zone_width_pct', $metrics['width_pct'] ?? null);
        $this->set('entry_zone_atr_pct', $metrics['atr_pct'] ?? null);
        $this->set('atr_timeframe', $metrics['atr_timeframe'] ?? null);
        $this->set('vwap_distance_pct', $metrics['vwap_distance_pct'] ?? null);
        $this->set('distance_from_zone_pct', $metrics['distance_from_zone_pct'] ?? null);
        $this->set('zone_direction', $metrics['zone_direction'] ?? null);
        $this->set('in_zone', $metrics['in_zone'] ?? null);

        return $this;
    }

    /**
     * @param array<string,mixed> $metrics
     */
    public function withMarket(array $metrics): self
    {
        $this->set('spread_bps', $metrics['spread_bps'] ?? null);
        $this->set('book_liquidity_score', $metrics['book_liquidity_score'] ?? null);
        $this->set('volatility_pct_1m', $metrics['volatility_pct_1m'] ?? null);
        $this->set('volume_ratio', $metrics['volume_ratio'] ?? null);
        $this->set('depth_top_usd', $metrics['depth_top_usd'] ?? null);

        return $this;
    }

    /**
     * @param array<string,mixed> $metrics
     */
    public function withPlan(array $metrics): self
    {
        $this->set('expected_r_multiple', $metrics['expected_r_multiple'] ?? ($this->data['expected_r_multiple'] ?? null));
        $this->set('r_stop_pct', $metrics['r_stop_pct'] ?? null);
        $this->set('r_tp1_pct', $metrics['r_tp1_pct'] ?? null);
        $this->set('leverage_target', $metrics['leverage_target'] ?? null);
        $this->set('conviction_score', $metrics['conviction_score'] ?? null);

        return $this;
    }

    /**
     * @param array<string,mixed> $metrics
     */
    public function withInfra(array $metrics): self
    {
        $this->set('latency_ms_ws', $metrics['latency_ms_ws'] ?? null);
        $this->set('latency_ms_rest', $metrics['latency_ms_rest'] ?? null);
        $this->set('worker_id', $metrics['worker_id'] ?? ($this->data['worker_id'] ?? null));

        return $this;
    }

    /**
     * Ajoute/écrase Brut des clés supplémentaires (utilisé juste avant persistence).
     *
     * @param array<string,mixed> $extra
     */
    public function merge(array $extra): self
    {
        foreach ($extra as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            if ($value === null) {
                unset($this->data[$key]);
                continue;
            }

            $this->data[$key] = $value;
        }

        return $this;
    }

    /**
     * Retourne les données filtrées de tout ce qui est null / tableau vide.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            $this->data,
            static fn($value) => $value !== null && $value !== []
        );
    }

    private function set(string $key, mixed $value): self
    {
        if ($value === null) {
            unset($this->data[$key]);

            return $this;
        }

        $this->data[$key] = $value;

        return $this;
    }
}
