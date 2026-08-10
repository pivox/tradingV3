<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Evaluation;

final readonly class RuleEvaluationContext
{
    /** @param array<string, RuleInputSnapshot> $snapshotsByTimeframe */
    public function __construct(
        public string $configHash,
        public \DateTimeImmutable $evaluatedAt,
        private array $snapshotsByTimeframe,
    ) {
        if ($configHash === '') {
            throw new \InvalidArgumentException('Effective config hash must be non-empty.');
        }
        foreach ($snapshotsByTimeframe as $timeframe => $snapshot) {
            if ($timeframe !== $snapshot->timeframe) {
                throw new \InvalidArgumentException('Rule input snapshot key/timeframe mismatch.');
            }
        }
    }

    public function snapshot(string $timeframe): ?RuleInputSnapshot
    {
        return $this->snapshotsByTimeframe[$timeframe] ?? null;
    }
}
