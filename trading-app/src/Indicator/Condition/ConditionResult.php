<?php

namespace App\Indicator\Condition;

final class ConditionResult
{
    const PASSED_KEY = 'passed';
    const NAME_KEY = 'name';
    const VALUE_KEY = 'value';
    const THRESHOLD_KEY = 'threshold';
    const META_KEY = 'meta';
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
            self::NAME_KEY => $this->name,
            self::PASSED_KEY => $this->passed,
            self::VALUE_KEY => $this->value,
            self::THRESHOLD_KEY => $this->threshold,
            self::META_KEY => $this->meta,
        ];
    }
}
