<?php

namespace App\MtfValidator\ConditionLoader\Cards\Validation;

use App\MtfValidator\ConditionLoader\Cards\AbstractCard;
use App\MtfValidator\ConditionLoader\Cards\Rule\Rule;
use App\MtfValidator\ConditionLoader\ConditionRegistry;

class SideElementSimple extends AbstractCard implements SideElementInterface
{
    private string $name = '';
    private array $overrides = [];
    private ?Rule $rule = null;

    public function __construct(
        private readonly ConditionRegistry $conditionRegistry
    ) {}

    /**
     * @throws \Exception
     */
    public function fill(string|array $data): static
    {
        $name = null;
        $overrides = [];

        if (is_array($data)) {
            $name = (string) array_key_first($data);
            $overrides = $this->normalizeOverrides($data[$name] ?? null);
        } else {
            $name = $data;
        }

        $this->name = $name;

        $condition = $this->conditionRegistry->get($name);
        if ($condition) {
            $this->condition = $condition;
        } else {
            $this->rule = ConditionRegistry::resolveRule($name);
        }
        $this->overrides = $overrides;

        return $this;
    }

    public function evaluate(array $payload): array
    {
        if ($this->condition) {
            $context = array_merge($payload, $this->overrides);
            $conditionResult = $this->condition->evaluate($context);
            $this->isValid = $conditionResult->passed;

            return $conditionResult->toArray();
        }

        if ($this->rule) {
            $result = $this->rule->evaluate($payload);
            $this->isValid = $result->passed;
            return $result->toArray();
        }

        throw new \RuntimeException(sprintf('Condition or rule "%s" not registered', $this->name));
    }

    private function normalizeOverrides(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if ($raw === null || $raw === true) {
            return [];
        }
        if (is_bool($raw)) {
            return ['expected' => $raw];
        }
        if (is_string($raw) && is_numeric($raw)) {
            return ['threshold' => (float) $raw];
        }
        if (is_numeric($raw)) {
            return ['threshold' => (float) $raw];
        }
        if (is_string($raw) && $raw !== '') {
            return ['value' => $raw];
        }

        return [];
    }
}
