<?php

declare(strict_types=1);

namespace App\Domain\PostValidation\Dto;

/**
 * DTO représentant la décision finale de l'étape Post-Validation
 */
final class PostValidationDecisionDto
{
    public const DECISION_OPEN = 'OPEN';
    public const DECISION_SKIP = 'SKIP';

    public function __construct(
        public readonly string $decision, // OPEN | SKIP
        public readonly string $reason,
        public readonly ?EntryZoneDto $entryZone,
        public readonly ?OrderPlanDto $orderPlan,
        public readonly array $marketData,
        public readonly array $guards,
        public readonly array $evidence,
        public readonly string $decisionKey,
        public readonly int $timestamp
    ) {
    }

    public function isOpen(): bool
    {
        return $this->decision === self::DECISION_OPEN;
    }

    public function isSkip(): bool
    {
        return $this->decision === self::DECISION_SKIP;
    }

    public function getExecutionTimeframe(): ?string
    {
        return $this->orderPlan?->executionTimeframe;
    }

    public function getSymbol(): string
    {
        return $this->entryZone?->symbol ?? $this->orderPlan?->symbol ?? 'UNKNOWN';
    }

    public function getSide(): ?string
    {
        return $this->entryZone?->side ?? $this->orderPlan?->side;
    }

    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'reason' => $this->reason,
            'symbol' => $this->getSymbol(),
            'side' => $this->getSide(),
            'execution_timeframe' => $this->getExecutionTimeframe(),
            'entry_zone' => $this->entryZone?->toArray(),
            'order_plan' => $this->orderPlan?->toArray(),
            'market_data' => $this->marketData,
            'guards' => $this->guards,
            'evidence' => $this->evidence,
            'decision_key' => $this->decisionKey,
            'timestamp' => $this->timestamp
        ];
    }
}

