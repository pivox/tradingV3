<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Catalog;

final readonly class ConditionParameterDefinition
{
    /** @param list<string|int|float> $values */
    public function __construct(
        public string $type,
        public bool $required,
        public mixed $default,
        public int|float|null $min,
        public int|float|null $max,
        public array $values,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'required' => $this->required,
            'default' => $this->default,
            'min' => $this->min,
            'max' => $this->max,
            'values' => $this->values === [] ? null : $this->values,
        ], static fn (mixed $value, string $key): bool => $value !== null || $key === 'default', ARRAY_FILTER_USE_BOTH);
    }
}
