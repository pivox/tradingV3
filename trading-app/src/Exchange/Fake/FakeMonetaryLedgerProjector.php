<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class FakeMonetaryLedgerProjector
{
    public const MONETARY_EVENT_TYPES = [
        'order.filled',
        'order.partially_filled',
        'liquidation.filled',
        'funding.accrued',
    ];

    private const SCALE = 12;
    private const PNL_SOURCE = 'fake_paper_fill_ledger_v1';

    /**
     * @param list<FakeExchangeEvent> $events
     */
    public function project(
        array $events,
        \DateTimeImmutable $observedAt,
        ?\DateTimeImmutable $startInclusive = null,
        ?\DateTimeImmutable $endExclusive = null,
    ): FakeMonetaryLedgerProjection {
        if (($startInclusive === null) !== ($endExclusive === null)
            || ($startInclusive !== null && $startInclusive >= $endExclusive)
        ) {
            throw new FakeMonetaryLedgerException('projection_window_invalid');
        }

        $net = BigDecimal::zero()->toScale(self::SCALE);
        $monetaryEventCount = 0;
        $duplicateEventCount = 0;
        $lastEventSequence = 0;
        /** @var array<int,string> $fingerprintsBySequence */
        $fingerprintsBySequence = [];
        /** @var array<string,string> $fingerprintsByFundingIdentity */
        $fingerprintsByFundingIdentity = [];
        /** @var list<array{sequence:int,fingerprint:string,amount:string}> $accepted */
        $accepted = [];

        foreach ($events as $event) {
            if (!$event instanceof FakeExchangeEvent || !$this->isMonetary($event)) {
                continue;
            }
            if ($event->occurredAt > $observedAt) {
                throw $this->failure(
                    'future_monetary_event',
                    $monetaryEventCount,
                    $duplicateEventCount,
                );
            }
            if ($startInclusive !== null
                && ($event->occurredAt < $startInclusive || $event->occurredAt >= $endExclusive)
            ) {
                continue;
            }

            $sequence = $this->positiveSequence($event->payload['event_sequence'] ?? null);
            if ($sequence === null) {
                throw $this->failure(
                    'event_sequence_invalid',
                    $monetaryEventCount,
                    $duplicateEventCount,
                );
            }
            $lastEventSequence = max($lastEventSequence, $sequence);
            try {
                $fingerprint = $this->fingerprint($event);
            } catch (\Throwable $exception) {
                throw $this->failure(
                    'monetary_event_invalid',
                    $monetaryEventCount,
                    $duplicateEventCount,
                    $exception,
                );
            }
            if (isset($fingerprintsBySequence[$sequence])) {
                if (hash_equals($fingerprintsBySequence[$sequence], $fingerprint)) {
                    ++$duplicateEventCount;

                    continue;
                }

                throw $this->failure(
                    'conflicting_event_sequence',
                    $monetaryEventCount,
                    $duplicateEventCount,
                );
            }
            $fingerprintsBySequence[$sequence] = $fingerprint;

            if ($event->type === 'funding.accrued') {
                $fundingIdentity = $event->payload['funding_idempotency_key'] ?? null;
                if (\is_string($fundingIdentity) && trim($fundingIdentity) !== '') {
                    if (isset($fingerprintsByFundingIdentity[$fundingIdentity])) {
                        if (hash_equals($fingerprintsByFundingIdentity[$fundingIdentity], $fingerprint)) {
                            ++$duplicateEventCount;

                            continue;
                        }

                        throw $this->failure(
                            'funding_idempotency_conflict',
                            $monetaryEventCount,
                            $duplicateEventCount,
                        );
                    }
                    $fingerprintsByFundingIdentity[$fundingIdentity] = $fingerprint;
                }
            }

            $delta = $event->type === 'funding.accrued'
                ? $this->fundingDelta($event)
                : $this->fillDelta($event);
            if ($delta['amount'] === null) {
                throw $this->failure(
                    $delta['reason'],
                    $monetaryEventCount,
                    $duplicateEventCount,
                );
            }

            $amount = $delta['amount']->toScale(self::SCALE, RoundingMode::HALF_EVEN);
            $net = $net->plus($amount)->toScale(self::SCALE, RoundingMode::HALF_EVEN);
            $accepted[] = [
                'sequence' => $sequence,
                'fingerprint' => $fingerprint,
                'amount' => (string) $amount,
            ];
            ++$monetaryEventCount;
        }

        usort($accepted, static fn (array $left, array $right): int => $left['sequence'] <=> $right['sequence']);
        $identity = [
            'source' => FakeMonetaryLedgerProjection::SOURCE,
            'source_version' => FakeMonetaryLedgerProjection::SOURCE_VERSION,
            'observed_at' => self::time($observedAt),
            'start_inclusive' => $startInclusive === null ? null : self::time($startInclusive),
            'end_exclusive' => $endExclusive === null ? null : self::time($endExclusive),
            'net_usdt' => (string) $net,
            'monetary_event_count' => $monetaryEventCount,
            'duplicate_event_count' => $duplicateEventCount,
            'last_event_sequence' => $lastEventSequence,
            'accepted_events' => $accepted,
        ];

        return new FakeMonetaryLedgerProjection(
            observedAt: $observedAt,
            startInclusive: $startInclusive,
            endExclusive: $endExclusive,
            netUsdt: (string) $net,
            monetaryEventCount: $monetaryEventCount,
            duplicateEventCount: $duplicateEventCount,
            lastEventSequence: $lastEventSequence,
            inputHash: 'sha256:' . hash('sha256', json_encode(
                $identity,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            )),
        );
    }

    public function isMonetary(FakeExchangeEvent $event): bool
    {
        return \in_array($event->type, self::MONETARY_EVENT_TYPES, true);
    }

    /** @return array{amount:?BigDecimal,reason:string} */
    private function fillDelta(FakeExchangeEvent $event): array
    {
        $payload = $event->payload;
        if (($payload['cost_completeness'] ?? null) !== 'complete') {
            return $this->invalidDelta('fill_cost_incomplete');
        }
        if (($payload['fee_currency'] ?? null) !== 'USDT') {
            return $this->invalidDelta('fill_fee_currency_unknown');
        }
        if (($payload['cost_model_version'] ?? null) !== FakeFillCostModel::MODEL_VERSION) {
            return $this->invalidDelta('fill_cost_model_unknown');
        }
        if (($payload['spread_model_version'] ?? null) !== FakeFillCostModel::SPREAD_MODEL_VERSION) {
            return $this->invalidDelta('fill_spread_model_unknown');
        }
        if (($payload['pnl_source'] ?? null) !== self::PNL_SOURCE) {
            return $this->invalidDelta('fill_pnl_source_unknown');
        }

        $quantity = $this->decimal($payload['fill_quantity'] ?? null);
        if ($quantity === null || !$quantity->isPositive()) {
            return $this->invalidDelta('fill_quantity_invalid');
        }
        $price = $this->decimal($payload['fill_price'] ?? null);
        if ($price === null || !$price->isPositive()) {
            return $this->invalidDelta('fill_price_invalid');
        }
        if (!\array_key_exists('fill_fee', $payload) || $payload['fill_fee'] === null) {
            return $this->invalidDelta('fill_fee_unknown');
        }
        $fee = $this->decimal($payload['fill_fee']);
        if ($fee === null || $fee->isNegative()) {
            return $this->invalidDelta('fill_fee_invalid');
        }
        $spread = $this->decimal($payload['spread_cost_usdt'] ?? null);
        if ($spread === null || $spread->isNegative()) {
            return $this->invalidDelta('fill_spread_cost_invalid');
        }
        $slippage = $this->decimal($payload['slippage_cost_usdt'] ?? null);
        if ($slippage === null || $slippage->isNegative()) {
            return $this->invalidDelta('fill_slippage_cost_invalid');
        }
        $liquidationFee = BigDecimal::zero()->toScale(self::SCALE);
        if ($event->type === 'liquidation.filled') {
            if (($payload['liquidation_fee_currency'] ?? null) !== 'USDT') {
                return $this->invalidDelta('liquidation_fee_currency_unknown');
            }
            if (($payload['liquidation_fee_model_version'] ?? null) !== FakeLiquidationPolicy::FEE_MODEL_VERSION) {
                return $this->invalidDelta('liquidation_fee_model_unknown');
            }
            $liquidationFeeValue = null;
            $liquidationFeePresent = false;
            foreach (['liquidation_fee_decimal', 'liquidation_fee_usdt_decimal'] as $key) {
                if (\array_key_exists($key, $payload)) {
                    $liquidationFeePresent = true;
                    $liquidationFeeValue = $payload[$key];
                    if (\is_float($liquidationFeeValue)) {
                        return $this->invalidDelta('liquidation_fee_exact_unknown');
                    }

                    break;
                }
            }
            if (!$liquidationFeePresent && \array_key_exists('liquidation_fee_usdt', $payload)) {
                $liquidationFeePresent = true;
                $liquidationFeeValue = $payload['liquidation_fee_usdt'];
                if (\is_float($liquidationFeeValue)) {
                    return $this->invalidDelta('liquidation_fee_exact_unknown');
                }
            }
            if (!$liquidationFeePresent || $liquidationFeeValue === null) {
                return $this->invalidDelta('liquidation_fee_unknown');
            }
            $liquidationFee = $this->decimal($liquidationFeeValue);
            if ($liquidationFee === null || !$liquidationFee->isPositive()) {
                return $this->invalidDelta('liquidation_fee_invalid');
            }
        }
        $snapshot = $payload['order_snapshot'] ?? null;
        if (!\is_array($snapshot) || !\is_bool($snapshot['reduce_only'] ?? null)) {
            return $this->invalidDelta('fill_reduce_intent_unknown');
        }

        $gross = BigDecimal::zero()->toScale(self::SCALE);
        if ($snapshot['reduce_only']) {
            if (!\array_key_exists('realized_gross_pnl_usdt', $payload) || $payload['realized_gross_pnl_usdt'] === null) {
                return $this->invalidDelta('realized_gross_pnl_unknown');
            }
            $gross = $this->decimal($payload['realized_gross_pnl_usdt']);
            if ($gross === null) {
                return $this->invalidDelta('realized_gross_pnl_invalid');
            }
        } elseif (($payload['realized_gross_pnl_usdt'] ?? null) !== null) {
            $entryGross = $this->decimal($payload['realized_gross_pnl_usdt']);
            if ($entryGross === null || !$entryGross->isZero()) {
                return $this->invalidDelta('entry_realized_pnl_invalid');
            }
        }

        return [
            'amount' => $gross->minus($fee)->minus($spread)->minus($slippage)->minus($liquidationFee)
                ->toScale(self::SCALE, RoundingMode::HALF_EVEN),
            'reason' => '',
        ];
    }

    /** @return array{amount:?BigDecimal,reason:string} */
    private function fundingDelta(FakeExchangeEvent $event): array
    {
        $payload = $event->payload;
        if (($payload['currency'] ?? null) !== 'USDT') {
            return $this->invalidDelta('funding_currency_unknown');
        }
        if (($payload['model_version'] ?? null) !== FakeFundingModelConfig::MODEL_VERSION) {
            return $this->invalidDelta('funding_model_unknown');
        }
        foreach (['funding_idempotency_key', 'funding_payload_hash'] as $key) {
            if (!\is_string($payload[$key] ?? null) || trim($payload[$key]) === '') {
                return $this->invalidDelta('funding_identity_unknown');
            }
        }
        if (!\array_key_exists('amount_usdt', $payload) || $payload['amount_usdt'] === null) {
            return $this->invalidDelta('funding_amount_usdt_unknown');
        }
        $amount = $this->decimal($payload['amount_usdt']);
        if ($amount === null) {
            return $this->invalidDelta('funding_amount_usdt_invalid');
        }
        if (!\array_key_exists('amount', $payload) || $payload['amount'] === null) {
            return $this->invalidDelta('funding_native_amount_unknown');
        }
        $nativeAmount = $this->decimal($payload['amount']);
        if ($nativeAmount === null) {
            return $this->invalidDelta('funding_native_amount_invalid');
        }
        if (!$nativeAmount->isEqualTo($amount)) {
            return $this->invalidDelta('funding_amount_usdt_conflict');
        }
        $dueAt = $payload['due_at'] ?? null;
        if (!\is_string($dueAt) || trim($dueAt) === '') {
            return $this->invalidDelta('funding_due_at_unknown');
        }
        try {
            $due = new \DateTimeImmutable($dueAt);
        } catch (\Throwable) {
            return $this->invalidDelta('funding_due_at_invalid');
        }
        if ($due->getTimestamp() !== $event->occurredAt->getTimestamp()) {
            return $this->invalidDelta('funding_due_at_conflict');
        }

        return ['amount' => $amount, 'reason' => ''];
    }

    private function decimal(mixed $value): ?BigDecimal
    {
        if (\is_int($value)) {
            $value = (string) $value;
        } elseif (\is_float($value)) {
            if (!\is_finite($value)) {
                return null;
            }
            $value = number_format($value, self::SCALE, '.', '');
        }
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^-?(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,12})?$/D', $value) !== 1) {
            return null;
        }

        try {
            return BigDecimal::of($value)->toScale(self::SCALE, RoundingMode::HALF_EVEN);
        } catch (\Throwable) {
            return null;
        }
    }

    private function positiveSequence(mixed $value): ?int
    {
        if (\is_int($value) && $value > 0) {
            return $value;
        }
        if (\is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function fingerprint(FakeExchangeEvent $event): string
    {
        $payload = $event->payload;
        unset($payload['event_sequence']);
        $canonical = [
            'type' => $event->type,
            'symbol' => $event->symbol,
            'occurred_at' => self::time($event->occurredAt),
            'payload' => $this->canonicalize($payload),
        ];

        return hash('sha256', json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** @return array{amount:null,reason:string} */
    private function invalidDelta(string $reason): array
    {
        return ['amount' => null, 'reason' => $reason];
    }

    private function failure(
        string $detailReason,
        int $monetaryEventCount,
        int $duplicateEventCount,
        ?\Throwable $previous = null,
    ): FakeMonetaryLedgerException {
        return new FakeMonetaryLedgerException(
            $detailReason,
            $monetaryEventCount,
            $duplicateEventCount,
            1,
            $previous,
        );
    }

    private static function time(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }
}
