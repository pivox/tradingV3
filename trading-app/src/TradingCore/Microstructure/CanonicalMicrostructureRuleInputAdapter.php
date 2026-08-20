<?php

declare(strict_types=1);

namespace App\TradingCore\Microstructure;

use App\TradingCore\Rules\Evaluation\RuleInputSnapshot;

final readonly class CanonicalMicrostructureRuleInputAdapter
{
    private const TIMESTAMP_FORMAT = '!Y-m-d\TH:i:s.u\Z';

    public function adapt(CanonicalMicrostructureSnapshot $snapshot): RuleInputSnapshot
    {
        $snapshot->verify();

        $observedAt = $this->timestamp($snapshot->evaluatedAt);
        $bookHappenedAt = $this->timestamp($snapshot->bookHappenedAt);
        $lastTradeHappenedAt = $this->timestamp($snapshot->lastTradeHappenedAt);
        $validUntil = min(
            $this->addSeconds($bookHappenedAt, $snapshot->policy['maximum_book_age_seconds']),
            $this->addSeconds($lastTradeHappenedAt, $snapshot->policy['maximum_trade_age_seconds']),
            $this->addSeconds($lastTradeHappenedAt, $snapshot->policy['maximum_trade_gap_seconds']),
        );
        if ($validUntil < $observedAt) {
            throw new CanonicalMicrostructureException('canonical_microstructure_rule_input_expired');
        }

        return new RuleInputSnapshot(
            timeframe: '1m',
            source: 'timestamped_order_book',
            observedAt: $observedAt,
            validUntil: $validUntil,
            values: [
                'spread_bps' => $this->finiteFloat($snapshot->spreadBps),
                'order_flow_imbalance' => $this->finiteFloat($snapshot->orderFlowImbalance),
                'order_flow_imbalance_definition' => $snapshot->orderFlowImbalanceDefinition,
                'microstructure_input_hash' => $snapshot->inputHash,
                'source_checksum' => $snapshot->sourceChecksum,
                'source_network' => $snapshot->sourceNetwork,
                'market_data_venue' => $snapshot->marketDataVenue,
                'market_type' => $snapshot->marketType,
                'symbol' => $snapshot->symbol,
                'quantity_unit' => $snapshot->quantityUnit,
                'microstructure_evaluated_at' => $snapshot->evaluatedAt,
                'microstructure_window_start' => $snapshot->windowStart,
                'microstructure_trade_count' => $snapshot->tradeCount,
            ],
        );
    }

    private function timestamp(string $value): \DateTimeImmutable
    {
        $timestamp = \DateTimeImmutable::createFromFormat(
            self::TIMESTAMP_FORMAT,
            $value,
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $timestamp->format('Y-m-d\TH:i:s.u\Z') !== $value
        ) {
            throw new CanonicalMicrostructureException('canonical_microstructure_rule_input_timestamp_invalid');
        }

        return $timestamp;
    }

    private function addSeconds(\DateTimeImmutable $timestamp, mixed $seconds): \DateTimeImmutable
    {
        if (!\is_int($seconds) || $seconds < 1) {
            throw new CanonicalMicrostructureException('canonical_microstructure_rule_input_policy_invalid');
        }

        return $timestamp->modify(sprintf('+%d seconds', $seconds));
    }

    private function finiteFloat(string $value): float
    {
        $converted = (float) $value;
        if (!\is_finite($converted)) {
            throw new CanonicalMicrostructureException('canonical_microstructure_rule_input_numeric_invalid');
        }

        return $converted;
    }
}
