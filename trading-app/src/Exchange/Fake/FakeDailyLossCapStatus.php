<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final readonly class FakeDailyLossCapStatus
{
    public const READY = 'ready';
    public const LIMIT_REACHED = 'limit_reached';
    public const NOT_COMPUTABLE = 'not_computable';

    public function __construct(
        public string $status,
        public string $utcDate,
        public ?string $limitUsdt,
        public ?string $dailyNetUsdt,
        public ?string $consumptionUsdt,
        public ?string $reason,
        public ?string $detailReason,
        public int $monetaryEventCount,
        public int $duplicateEventCount,
        public int $invalidEventCount,
        public int $rejectionCount,
    ) {
        if (!\in_array($status, [self::READY, self::LIMIT_REACHED, self::NOT_COMPUTABLE], true)) {
            throw new \InvalidArgumentException('fake_daily_loss_cap_status_invalid');
        }
    }

    public function blocksExposureIncrease(): bool
    {
        return $this->status !== self::READY;
    }

    /** @return array<string,bool|int|string|null> */
    public function toAuditMetadata(): array
    {
        return [
            'daily_loss_cap_policy_version' => FakeDailyLossCapPolicy::VERSION,
            'daily_loss_cap_utc_date' => $this->utcDate,
            'daily_loss_cap_status' => $this->status,
            'daily_loss_cap_limit_usdt' => $this->limitUsdt,
            'daily_loss_cap_daily_net_usdt' => $this->dailyNetUsdt,
            'daily_loss_cap_consumption_usdt' => $this->consumptionUsdt,
            'daily_loss_cap_detail_reason' => $this->detailReason,
            'daily_loss_cap_monetary_event_count' => $this->monetaryEventCount,
            'daily_loss_cap_duplicate_event_count' => $this->duplicateEventCount,
            'daily_loss_cap_invalid_event_count' => $this->invalidEventCount,
            'daily_loss_cap_rejection_count' => $this->rejectionCount,
        ];
    }
}
