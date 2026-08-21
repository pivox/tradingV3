<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderType;
use Brick\Math\BigDecimal;
use Psr\Clock\ClockInterface;

final readonly class FakeDailyLossCapGuard
{
    public function __construct(
        private FakeExchangeStateStore $stateStore,
        private ClockInterface $clock,
        private FakeDailyLossCapPolicy $policy,
        private ?FakeMonetaryLedgerProjector $ledgerProjector = null,
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
        $rejectionCount = 0;
        try {
            $events = $this->stateStore->events();
        } catch (\Throwable) {
            return $this->notComputable($utcDate, $limitUsdt, 'state_event_ledger_unavailable');
        }

        foreach ($events as $event) {
            if ($event->occurredAt >= $start
                && $event->occurredAt < $end
                && $event->occurredAt <= $now
                && $event->type === 'order.rejected'
                && \in_array($event->payload['reason'] ?? null, [
                    'daily_loss_cap_reached',
                    'daily_loss_cap_not_computable',
                ], true)
            ) {
                ++$rejectionCount;
            }
        }

        try {
            $projection = ($this->ledgerProjector ?? new FakeMonetaryLedgerProjector())
                ->project($events, $now, $start, $end);
        } catch (FakeMonetaryLedgerException $exception) {
            return $this->notComputable(
                $utcDate,
                $limitUsdt,
                $exception->detailReason,
                $exception->monetaryEventCount,
                $exception->duplicateEventCount,
                $exception->invalidEventCount,
                $rejectionCount,
            );
        }

        $dailyNet = BigDecimal::of($projection->netUsdt);
        $consumption = $dailyNet->isNegative() ? $dailyNet->negated() : BigDecimal::zero();
        $consumptionUsdt = (string) $consumption->toScale(12);
        $limit = BigDecimal::of($limitUsdt);
        $reached = $consumption->isGreaterThanOrEqualTo($limit);

        return new FakeDailyLossCapStatus(
            status: $reached ? FakeDailyLossCapStatus::LIMIT_REACHED : FakeDailyLossCapStatus::READY,
            utcDate: $utcDate,
            limitUsdt: $limitUsdt,
            dailyNetUsdt: $projection->netUsdt,
            consumptionUsdt: $consumptionUsdt,
            reason: $reached ? 'daily_loss_cap_reached' : null,
            detailReason: $reached ? 'consumption_at_or_above_limit' : null,
            monetaryEventCount: $projection->monetaryEventCount,
            duplicateEventCount: $projection->duplicateEventCount,
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
