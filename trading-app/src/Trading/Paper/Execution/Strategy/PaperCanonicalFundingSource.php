<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;
use Brick\Math\BigDecimal;

final readonly class PaperCanonicalFundingSource
{
    /** @var list<string> */
    private const PAYLOAD_KEYS = [
        'funding_schema_version', 'native_symbol', 'instrument_type', 'funding_rate',
        'observed_at_ms', 'funding_time_ms', 'next_funding_time_ms', 'funding_interval_seconds',
        'method', 'formula_type', 'settlement_state', 'source_epoch', 'origin',
    ];

    public function __construct(
        private PaperMarketStateProjector $market,
        private PaperReplayClock $clock,
    ) {
    }

    public function snapshotFor(
        PaperExecutionCell $cell,
        PaperMarketEvent $trigger,
        int $requiredIntervalSeconds,
    ): ?PaperCanonicalFundingSnapshot {
        if (!$cell->isModern()) {
            throw new \LogicException('paper_canonical_strategy_cell_identity_missing');
        }
        if ($trigger->sourceNetwork !== $cell->network || $trigger->sourceVenue !== $cell->marketDataVenue) {
            throw new \LogicException('paper_canonical_strategy_market_scope_mismatch');
        }
        if ($cell->marketDataVenue !== PaperMarketDataVenue::OKX) {
            return null;
        }
        if ($requiredIntervalSeconds < 1) {
            throw new \LogicException('paper_canonical_funding_interval_invalid');
        }

        $now = $this->clock->now();
        if ($trigger->exchangeTimestamp > $now || $trigger->receivedTimestamp > $now) {
            return null;
        }
        $events = array_values(array_filter(
            $this->market->events(),
            static fn (PaperMarketEvent $event): bool =>
                $event->sourceNetwork === $cell->network
                && $event->sourceVenue === $cell->marketDataVenue
                && $event->symbol === $trigger->symbol,
        ));
        $latest = $events === [] ? null : $events[array_key_last($events)];
        if (!$latest instanceof PaperMarketEvent
            || !hash_equals($latest->eventId, $trigger->eventId)
            || !hash_equals(CanonicalJson::encode($latest->toArray()), CanonicalJson::encode($trigger->toArray()))
        ) {
            throw new \LogicException('paper_canonical_strategy_trigger_not_current');
        }

        $funding = array_values(array_filter(
            $events,
            static fn (PaperMarketEvent $event): bool =>
                $event->channel === PaperMarketDataChannel::FUNDING_RATE
                && $event->exchangeTimestamp <= $now
                && $event->receivedTimestamp <= $now,
        ));
        if ($funding === []) {
            return null;
        }
        usort($funding, static fn (PaperMarketEvent $left, PaperMarketEvent $right): int =>
            [$left->receivedTimestamp, $left->exchangeTimestamp, $left->eventId]
            <=> [$right->receivedTimestamp, $right->exchangeTimestamp, $right->eventId]);
        $event = $funding[array_key_last($funding)];
        $payload = $event->payload;
        $keys = array_keys($payload);
        sort($keys, \SORT_STRING);
        $expected = self::PAYLOAD_KEYS;
        sort($expected, \SORT_STRING);

        try {
            $native = $trigger->symbol === 'BTCUSDT' ? 'BTC-USDT-SWAP' : 'ETH-USDT-SWAP';
            $rate = $payload['funding_rate'] ?? null;
            $interval = $payload['funding_interval_seconds'] ?? null;
            if ($keys !== $expected
                || ($payload['funding_schema_version'] ?? null) !== 'paper-funding-rate.v1'
                || ($payload['native_symbol'] ?? null) !== $native
                || ($payload['instrument_type'] ?? null) !== 'perpetual'
                || !\is_string($rate)
                || \strlen($rate) > 128
                || (string) BigDecimal::of($rate)->stripTrailingZeros() !== $rate
                || BigDecimal::of($rate)->isLessThanOrEqualTo(-1)
                || BigDecimal::of($rate)->isGreaterThanOrEqualTo(1)
                || !\is_int($interval) || $interval < 1
                || ($payload['method'] ?? null) !== 'current_period'
                || ($payload['formula_type'] ?? null) !== 'withRate'
                || !\in_array($payload['settlement_state'] ?? null, ['processing', 'settled'], true)
                || !\is_int($payload['source_epoch'] ?? null) || $payload['source_epoch'] < 1
                || ($payload['origin'] ?? null) !== 'rest_public_funding_rate'
                || !$this->validSchedule($payload, $interval)
                || $this->timestamp($payload['observed_at_ms']) > $now
                || $this->timestamp($payload['observed_at_ms']) > $event->receivedTimestamp
                || $event->exchangeTimestamp != $event->receivedTimestamp
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            throw new \LogicException('paper_canonical_funding_evidence_invalid');
        }
        if ($interval !== $requiredIntervalSeconds) {
            throw new \LogicException('paper_canonical_funding_interval_mismatch');
        }
        $observedAt = $this->timestamp($payload['observed_at_ms']);
        if ($observedAt->modify('+' . $interval . ' seconds') < $now) {
            return null;
        }

        return new PaperCanonicalFundingSnapshot(
            'venue_schedule',
            BigDecimal::of($rate)->toFloat(),
            $interval,
            $observedAt,
            'sha256:' . $event->eventId,
        );
    }

    /** @param array<string, mixed> $payload */
    private function validSchedule(array $payload, int $interval): bool
    {
        $funding = $payload['funding_time_ms'] ?? null;
        $next = $payload['next_funding_time_ms'] ?? null;
        $observed = $payload['observed_at_ms'] ?? null;
        if (!\is_string($funding) || !\is_string($next) || !\is_string($observed)
            || preg_match('/\A[1-9][0-9]{12}\z/D', $funding) !== 1
            || preg_match('/\A[1-9][0-9]{12}\z/D', $next) !== 1
            || preg_match('/\A[1-9][0-9]{12}\z/D', $observed) !== 1
        ) {
            return false;
        }

        return BigDecimal::of($next)->minus($funding)->isEqualTo($interval * 1000)
            && BigDecimal::of($observed)->isLessThan($funding);
    }

    private function timestamp(mixed $milliseconds): \DateTimeImmutable
    {
        if (!\is_string($milliseconds) || preg_match('/\A[1-9][0-9]{12}\z/D', $milliseconds) !== 1) {
            throw new \LogicException('paper_canonical_funding_evidence_invalid');
        }
        $seconds = substr($milliseconds, 0, -3);
        $micros = substr($milliseconds, -3) . '000';
        $timestamp = \DateTimeImmutable::createFromFormat('U.u', $seconds . '.' . $micros);
        if (!$timestamp instanceof \DateTimeImmutable) {
            throw new \LogicException('paper_canonical_funding_evidence_invalid');
        }

        return $timestamp->setTimezone(new \DateTimeZone('UTC'));
    }
}
