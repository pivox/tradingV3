<?php

namespace App\MtfValidator\ConditionLoader\Cards\Rule;

use App\MtfValidator\ConditionLoader\Cards\AbstractCard;
use App\MtfValidator\ConditionLoader\ConditionRegistry;

class Rules extends AbstractCard implements RuleElementInterface
{
    /** @var array<string,Rule> */
    private array $rules = [];

    public function __construct(
        private readonly ConditionRegistry $conditionRegistry
    ) {}

    public function fill(array|string $payload): static
    {
        $data = is_array($payload) ? ($payload['rules'] ?? $payload) : [];
        $this->rules = [];

        foreach ($data as $ruleName => $ruleSpec) {
            $rule = (new Rule($this->conditionRegistry))->fill([$ruleName => $ruleSpec]);
            $this->rules[$ruleName] = $rule;
        }
        return $this;
    }

    /**
     * @return array<string,Rule>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    public function has(string $name): bool
    {
        return isset($this->rules[$name]);
    }

    public function get(string $name): ?Rule
    {
        return $this->rules[$name] ?? null;
    }
}
