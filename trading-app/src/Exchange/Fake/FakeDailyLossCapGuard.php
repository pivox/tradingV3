<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Psr\Clock\ClockInterface;

final readonly class FakeDailyLossCapGuard
{
    private const SCALE = 12;
    private const PNL_SOURCE = 'fake_paper_fill_ledger_v1';

    public function __construct(
        private FakeExchangeStateStore $stateStore,
        private ClockInterface $clock,
        private FakeDailyLossCapPolicy $policy,
    ) {
    }

    public function current(): FakeDailyLossCapStatus
    {
        try {
            $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return $this->notComputable(
                utcDate: 'unknown',
                limitUsdt: $this->policy->normalizedLimitUsdt(),
                detailReason: 'clock_not_ready',
            );
        }

        $utcDate = $now->format('Y-m-d');
        $limitUsdt = $this->policy->normalizedLimitUsdt();
        if ($limitUsdt === null) {
            return $this->notComputable($utcDate, null, 'invalid_daily_loss_cap_limit');
        }

        $start = $now->setTime(0, 0);
        $end = $start->modify('+1 day');
        $dailyNet = BigDecimal::zero()->toScale(self::SCALE);
        $monetaryEventCount = 0;
        $duplicateEventCount = 0;
        $rejectionCount = 0;
        /** @var array<int,string> $fingerprintsBySequence */
        $fingerprintsBySequence = [];
        /** @var array<string,string> canonical fingerprints excluding event_sequence */
        $fingerprintsByFundingIdentity = [];
        try {
            $events = $this->stateStore->events();
        } catch (\Throwable) {
            return $this->notComputable($utcDate, $limitUsdt, 'state_event_ledger_unavailable');
        }

        foreach ($events as $event) {
            if ($event->occurredAt >= $start && $event->occurredAt < $end && $event->occurredAt <= $now) {
                if (
                    $event->type === 'order.rejected'
                    && \in_array($event->payload['reason'] ?? null, [
                        'daily_loss_cap_reached',
                        'daily_loss_cap_not_computable',
                    ], true)
                ) {
                    ++$rejectionCount;
                }
            }

            if (!$this->isMonetary($event)) {
                continue;
            }
            if ($event->occurredAt > $now) {
                return $this->notComputable(
                    $utcDate,
                    $limitUsdt,
                    'future_monetary_event',
                    $monetaryEventCount,
                    $duplicateEventCount,
                    1,
                    $rejectionCount,
                );
            }
            if ($event->occurredAt < $start || $event->occurredAt >= $end) {
                continue;
            }

            $sequence = $this->positiveSequence($event->payload['event_sequence'] ?? null);
            if ($sequence === null) {
                return $this->notComputable(
                    $utcDate,
                    $limitUsdt,
                    'event_sequence_invalid',
                    $monetaryEventCount,
                    $duplicateEventCount,
                    1,
                    $rejectionCount,
                );
            }
            try {
                $fingerprint = $this->fingerprint($event);
            } catch (\Throwable) {
                return $this->notComputable(
                    $utcDate,
                    $limitUsdt,
                    'monetary_event_invalid',
                    $monetaryEventCount,
                    $duplicateEventCount,
                    1,
                    $rejectionCount,
                );
            }
            if (isset($fingerprintsBySequence[$sequence])) {
                if (hash_equals($fingerprintsBySequence[$sequence], $fingerprint)) {
                    ++$duplicateEventCount;

                    continue;
                }

                return $this->notComputable(
                    $utcDate,
                    $limitUsdt,
                    'conflicting_event_sequence',
                    $monetaryEventCount,
                    $duplicateEventCount,
                    1,
                    $rejectionCount,
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

                        return $this->notComputable(
                            $utcDate,
                            $limitUsdt,
                            'funding_idempotency_conflict',
                            $monetaryEventCount,
                            $duplicateEventCount,
                            1,
                            $rejectionCount,
                        );
                    }
                    $fingerprintsByFundingIdentity[$fundingIdentity] = $fingerprint;
                }
            }

            $delta = $event->type === 'funding.accrued'
                ? $this->fundingDelta($event)
                : $this->fillDelta($event);
            if ($delta['amount'] === null) {
                return $this->notComputable(
                    $utcDate,
                    $limitUsdt,
                    $delta['reason'],
                    $monetaryEventCount,
                    $duplicateEventCount,
                    1,
                    $rejectionCount,
                );
            }

            $dailyNet = $dailyNet->plus($delta['amount'])->toScale(self::SCALE, RoundingMode::HALF_EVEN);
            ++$monetaryEventCount;
        }

        $consumption = $dailyNet->isNegative() ? $dailyNet->negated() : BigDecimal::zero();
        $dailyNetUsdt = (string) $dailyNet->toScale(self::SCALE, RoundingMode::HALF_EVEN);
        $consumptionUsdt = (string) $consumption->toScale(self::SCALE, RoundingMode::HALF_EVEN);
        $limit = BigDecimal::of($limitUsdt);
        $reached = $consumption->isGreaterThanOrEqualTo($limit);

        return new FakeDailyLossCapStatus(
            status: $reached ? FakeDailyLossCapStatus::LIMIT_REACHED : FakeDailyLossCapStatus::READY,
            utcDate: $utcDate,
            limitUsdt: $limitUsdt,
            dailyNetUsdt: $dailyNetUsdt,
            consumptionUsdt: $consumptionUsdt,
            reason: $reached ? 'daily_loss_cap_reached' : null,
            detailReason: $reached ? 'consumption_at_or_above_limit' : null,
            monetaryEventCount: $monetaryEventCount,
            duplicateEventCount: $duplicateEventCount,
            invalidEventCount: 0,
            rejectionCount: $rejectionCount,
        );
    }

    /** @return array<string,bool|int|string|null>|null */
    public function rejectionMetadata(PlaceOrderRequest|ExchangeOrderDto $order): ?array
    {
        if ($order->reduceOnly || \in_array($order->orderType, [
            ExchangeOrderType::STOP_LOSS,
            ExchangeOrderType::TAKE_PROFIT,
            ExchangeOrderType::TRIGGER,
        ], true)) {
            return null;
        }

        $status = $this->current();

        return $status->blocksExposureIncrease() ? $status->toAuditMetadata() : null;
    }

    private function isMonetary(FakeExchangeEvent $event): bool
    {
        return \in_array($event->type, [
            'order.filled',
            'order.partially_filled',
            'liquidation.filled',
            'funding.accrued',
        ], true);
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
            if (!\array_key_exists('liquidation_fee_usdt', $payload) || $payload['liquidation_fee_usdt'] === null) {
                return $this->invalidDelta('liquidation_fee_unknown');
            }
            if (($payload['liquidation_fee_currency'] ?? null) !== 'USDT') {
                return $this->invalidDelta('liquidation_fee_currency_unknown');
            }
            if (($payload['liquidation_fee_model_version'] ?? null) !== FakeLiquidationPolicy::FEE_MODEL_VERSION) {
                return $this->invalidDelta('liquidation_fee_model_unknown');
            }
            $liquidationFee = $this->decimal($payload['liquidation_fee_usdt']);
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
            'occurred_at' => $event->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'),
            'payload' => $this->canonicalize($payload),
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
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

    private function notComputable(
        string $utcDate,
        ?string $limitUsdt,
        string $detailReason,
        int $monetaryEventCount = 0,
        int $duplicateEventCount = 0,
        int $invalidEventCount = 1,
        int $rejectionCount = 0,
    ): FakeDailyLossCapStatus {
        return new FakeDailyLossCapStatus(
            status: FakeDailyLossCapStatus::NOT_COMPUTABLE,
            utcDate: $utcDate,
            limitUsdt: $limitUsdt,
            dailyNetUsdt: null,
            consumptionUsdt: null,
            reason: 'daily_loss_cap_not_computable',
            detailReason: $detailReason,
            monetaryEventCount: $monetaryEventCount,
            duplicateEventCount: $duplicateEventCount,
            invalidEventCount: $invalidEventCount,
            rejectionCount: $rejectionCount,
        );
    }
}
