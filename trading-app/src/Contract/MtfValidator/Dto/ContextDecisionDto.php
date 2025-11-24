<?php

declare(strict_types=1);

namespace App\Contract\MtfValidator\Dto;

final class ContextDecisionDto
{
    /**
     * @param TimeframeDecisionDto[] $timeframeDecisions
     */
    public function __construct(
        public readonly bool $isValid,
        public readonly ?string $reasonIfInvalid,
        public readonly array $timeframeDecisions,
    ) {
    }

    public function toArray(): array
    {
        return [
            'valid' => $this->isValid,
            'reason_if_invalid' => $this->reasonIfInvalid,
            'timeframe_decisions' => $this->timeframeDecisions

        ];
    }
}
