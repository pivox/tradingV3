<?php

declare(strict_types=1);

namespace App\Contract\MtfValidator\Dto;

/**
 * DTO pour les résultats de traitement de timeframe
 */
final class TimeframeResultDto
{
    public function __construct(
        public readonly string $timeframe,
        public readonly string $status,
        public readonly ?string $signalSide = null,
        public readonly ?string $klineTime = null,
        public readonly ?float $currentPrice = null,
        public readonly ?float $atr = null,
        public readonly ?array $indicatorContext = null,
        public readonly array $conditionsLong = [],
        public readonly array $conditionsShort = [],
        public readonly array $failedConditionsLong = [],
        public readonly array $failedConditionsShort = [],
        public readonly ?string $reason = null,
        public readonly ?array $error = null
    ) {}

    public function isSuccess(): bool
    {
        return $this->status === 'VALID';
    }

    public function isError(): bool
    {
        return $this->status === 'ERROR';
    }

    public function isSkipped(): bool
    {
        return in_array($this->status, ['SKIPPED', 'GRACE_WINDOW', 'TOO_RECENT']);
    }

    public function isInvalid(): bool
    {
        return $this->status === 'INVALID';
    }

    public function toArray(): array
    {
        return [
            'timeframe' => $this->timeframe,
            'status' => $this->status,
            'signal_side' => $this->signalSide,
            'kline_time' => $this->klineTime,
            'current_price' => $this->currentPrice,
            'atr' => $this->atr,
            'indicator_context' => $this->indicatorContext,
            'conditions_long' => $this->conditionsLong,
            'conditions_short' => $this->conditionsShort,
            'failed_conditions_long' => $this->failedConditionsLong,
            'failed_conditions_short' => $this->failedConditionsShort,
            'reason' => $this->reason,
            'error' => $this->error
        ];
    }
}
