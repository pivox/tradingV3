<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\TradingCore\Microstructure\CanonicalMicrostructureSnapshot;
use App\TradingCore\Microstructure\CanonicalMicrostructureRuleInputAdapter;
use App\TradingCore\Rules\Evaluation\RuleMarketIdentity;

final class MicrostructureProof
{
    /**
     * @param array<string, mixed> $context
     * @return array{value: ?float, threshold: ?float, meta: array<string, mixed>}
     */
    public static function validate(array $context, string $metricKey, string $thresholdKey): array
    {
        $snapshot = $context['_input_proof'] ?? null;
        if (!$snapshot instanceof CanonicalMicrostructureSnapshot) {
            return self::failure('microstructure_proof_snapshot_missing');
        }
        try {
            $snapshot->verify();
        } catch (\Throwable) {
            return self::failure('microstructure_proof_snapshot_invalid');
        }

        $checks = [
            ['_input_source', static fn (mixed $value): bool => $value === 'timestamped_order_book', 'microstructure_proof_source_invalid'],
            ['timeframe', static fn (mixed $value): bool => $value === '1m', 'microstructure_proof_timeframe_invalid'],
            ['order_flow_imbalance_definition', static fn (mixed $value): bool => $value === 'aggressor_volume_ratio.v1', 'microstructure_proof_definition_invalid'],
            ['microstructure_input_hash', self::sha256(...), 'microstructure_proof_input_hash_invalid'],
            ['source_checksum', self::sha256(...), 'microstructure_proof_source_checksum_invalid'],
            ['source_network', static fn (mixed $value): bool => \in_array($value, ['mainnet', 'testnet'], true), 'microstructure_proof_network_invalid'],
            ['market_data_venue', static fn (mixed $value): bool => \in_array($value, ['okx', 'hyperliquid'], true), 'microstructure_proof_venue_invalid'],
            ['market_type', static fn (mixed $value): bool => $value === 'perpetual', 'microstructure_proof_market_type_invalid'],
            ['symbol', static fn (mixed $value): bool => \is_string($value) && preg_match('/\A[A-Z0-9][A-Z0-9_.-]*\z/D', $value) === 1, 'microstructure_proof_symbol_invalid'],
        ];
        foreach ($checks as [$key, $valid, $reason]) {
            if (!$valid($context[$key] ?? null)) {
                return self::failure($reason);
            }
        }

        $expectedUnit = $context['market_data_venue'] === 'okx' ? 'contracts' : 'base_asset';
        if (($context['quantity_unit'] ?? null) !== $expectedUnit) {
            return self::failure('microstructure_proof_quantity_unit_invalid');
        }

        foreach ([
            'order_flow_imbalance_definition' => $snapshot->orderFlowImbalanceDefinition,
            'microstructure_input_hash' => $snapshot->inputHash,
            'source_checksum' => $snapshot->sourceChecksum,
            'source_network' => $snapshot->sourceNetwork,
            'market_data_venue' => $snapshot->marketDataVenue,
            'market_type' => $snapshot->marketType,
            'symbol' => $snapshot->symbol,
            'quantity_unit' => $snapshot->quantityUnit,
        ] as $key => $expected) {
            if (($context[$key] ?? null) !== $expected) {
                return self::failure('microstructure_proof_snapshot_binding_invalid');
            }
        }
        if (!self::sameInstant($context['_input_observed_at'] ?? null, $snapshot->evaluatedAt)) {
            return self::failure('microstructure_proof_snapshot_time_invalid');
        }
        if (!self::validityIsBounded($context['_input_valid_until'] ?? null, $snapshot)) {
            return self::failure('microstructure_proof_validity_invalid');
        }
        $expectedIdentity = $context['_expected_market_identity'] ?? null;
        if (!$expectedIdentity instanceof RuleMarketIdentity) {
            return self::failure('microstructure_proof_expected_identity_missing');
        }
        if (!$expectedIdentity->matches(
            $snapshot->sourceNetwork,
            $snapshot->marketDataVenue,
            $snapshot->marketType,
            $snapshot->symbol,
            $snapshot->quantityUnit,
        )) {
            return self::failure('microstructure_proof_expected_identity_mismatch');
        }

        $value = self::number($context[$metricKey] ?? null);
        if ($value === null
            || ($metricKey === 'spread_bps' && $value < 0.0)
            || ($metricKey === 'order_flow_imbalance' && ($value < -1.0 || $value > 1.0))
        ) {
            return self::failure('microstructure_proof_metric_invalid');
        }
        $snapshotValue = self::number($metricKey === 'spread_bps' ? (float) $snapshot->spreadBps : (float) $snapshot->orderFlowImbalance);
        if ($snapshotValue === null || $value !== $snapshotValue) {
            return self::failure('microstructure_proof_snapshot_metric_invalid');
        }
        $threshold = self::number($context[$thresholdKey] ?? null);
        if ($threshold === null
            || ($metricKey === 'spread_bps' && $threshold < 0.0)
            || ($metricKey === 'order_flow_imbalance' && ($threshold < -1.0 || $threshold > 1.0))
        ) {
            return self::failure('microstructure_proof_threshold_invalid');
        }

        return ['value' => $value, 'threshold' => $threshold, 'meta' => []];
    }

    private static function sha256(mixed $value): bool
    {
        return \is_string($value) && preg_match('/\Asha256:[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function number(mixed $value): ?float
    {
        if (!\is_int($value) && !\is_float($value)) {
            return null;
        }
        $number = (float) $value;

        return \is_finite($number) ? $number : null;
    }

    private static function sameInstant(mixed $observedAt, string $snapshotEvaluatedAt): bool
    {
        $observed = self::instant($observedAt);
        $expected = self::instant($snapshotEvaluatedAt);

        return $observed !== null && $expected !== null && $observed == $expected;
    }

    private static function validityIsBounded(mixed $validUntil, CanonicalMicrostructureSnapshot $snapshot): bool
    {
        $actual = self::instant($validUntil);
        $observed = self::instant($snapshot->evaluatedAt);
        $book = self::instant($snapshot->bookHappenedAt);
        $trade = self::instant($snapshot->lastTradeHappenedAt);
        if ($actual === null || $observed === null || $book === null || $trade === null) {
            return false;
        }
        $maximum = min(
            $observed->modify('+' . CanonicalMicrostructureRuleInputAdapter::CATALOG_FRESHNESS_SECONDS . ' seconds'),
            $book->modify('+' . $snapshot->policy['maximum_book_age_seconds'] . ' seconds'),
            $trade->modify('+' . $snapshot->policy['maximum_trade_age_seconds'] . ' seconds'),
            $trade->modify('+' . $snapshot->policy['maximum_trade_gap_seconds'] . ' seconds'),
        );

        return $actual >= $observed && $actual <= $maximum;
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array{value: null, threshold: null, meta: array{missing_data: true, proof_reason: string}} */
    private static function failure(string $reason): array
    {
        return [
            'value' => null,
            'threshold' => null,
            'meta' => ['missing_data' => true, 'proof_reason' => $reason],
        ];
    }
}
