<?php

namespace App\Indicator\Condition;

final class ConditionResult
{
    public function __construct(
        public string $name,
        public bool $passed,
        public ?float $value = null,
        public ?float $threshold = null,
        public array $meta = []
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'passed' => $this->passed,
            'value' => $this->value,
            'threshold' => $this->threshold,
            'meta' => $this->meta,
        ];
    }
}
