<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Evaluation;

final readonly class RuleEvaluationContext
{
    /** @var array<string, RuleInputSnapshot> */
    private array $snapshotsByIdentity;

    /** @param list<RuleInputSnapshot> $snapshots */
    public function __construct(
        public string $configHash,
        public \DateTimeImmutable $evaluatedAt,
        array $snapshots,
    ) {
        if ($configHash === '') {
            throw new \InvalidArgumentException('Effective config hash must be non-empty.');
        }
        $indexed = [];
        foreach ($snapshots as $snapshot) {
            $identity = self::identity($snapshot->timeframe, $snapshot->source);
            if (isset($indexed[$identity])) {
                throw new \InvalidArgumentException('Duplicate rule input timeframe/source snapshot.');
            }
            $indexed[$identity] = $snapshot;
        }
        ksort($indexed, SORT_STRING);
        $this->snapshotsByIdentity = $indexed;
    }

    public function snapshot(string $timeframe, string $source): ?RuleInputSnapshot
    {
        return $this->snapshotsByIdentity[self::identity($timeframe, $source)] ?? null;
    }

    private static function identity(string $timeframe, string $source): string
    {
        return $timeframe . "\0" . $source;
    }
}
