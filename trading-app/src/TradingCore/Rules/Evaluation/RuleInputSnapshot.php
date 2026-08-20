<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Evaluation;

final readonly class RuleInputSnapshot
{
    /** @param array<string, mixed> $values */
    public function __construct(
        public string $timeframe,
        public string $source,
        public \DateTimeImmutable $observedAt,
        public \DateTimeImmutable $validUntil,
        public array $values,
        public ?RuleInputProof $proof = null,
    ) {
        if ($timeframe === '' || $source === '') {
            throw new \InvalidArgumentException('Rule input timeframe and source must be non-empty.');
        }
        if ($validUntil < $observedAt) {
            throw new \InvalidArgumentException('Rule input validity cannot end before observation.');
        }
    }

    public function isValidAt(\DateTimeImmutable $instant): bool
    {
        return $this->observedAt <= $instant && $instant <= $this->validUntil;
    }
}
