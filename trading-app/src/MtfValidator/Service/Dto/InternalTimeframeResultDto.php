<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Dto;

/**
 * DTO interne pour les résultats de timeframe
 */
final class InternalTimeframeResultDto
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
        public readonly ?array $error = null,
        public readonly ?array $metadata = null
    ) {}

    public function toContractDto(): \App\Contract\MtfValidator\Dto\TimeframeResultDto
    {
        return new \App\Contract\MtfValidator\Dto\TimeframeResultDto(
            timeframe: $this->timeframe,
            status: $this->status,
            signalSide: $this->signalSide,
            klineTime: $this->klineTime,
            currentPrice: $this->currentPrice,
            atr: $this->atr,
            indicatorContext: $this->indicatorContext,
            conditionsLong: $this->conditionsLong,
            conditionsShort: $this->conditionsShort,
            failedConditionsLong: $this->failedConditionsLong,
            failedConditionsShort: $this->failedConditionsShort,
            reason: $this->reason,
            error: $this->error
        );
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
            'error' => $this->error,
            'metadata' => $this->metadata
        ];
    }
}
