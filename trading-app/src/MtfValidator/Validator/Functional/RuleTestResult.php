<?php

declare(strict_types=1);

namespace App\MtfValidator\Validator\Functional;

/**
 * DTO représentant le résultat d'un test de règle
 */
final class RuleTestResult
{
    public function __construct(
        private readonly string $ruleName,
        private readonly bool $passed,
        private readonly mixed $actualValue,
        private readonly mixed $expectedValue,
        private readonly string $contextDescription,
        private readonly array $metadata = []
    ) {
    }

    public function getRuleName(): string
    {
        return $this->ruleName;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function getActualValue(): mixed
    {
        return $this->actualValue;
    }

    public function getExpectedValue(): mixed
    {
        return $this->expectedValue;
    }

    public function getContextDescription(): string
    {
        return $this->contextDescription;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function toArray(): array
    {
        return [
            'rule' => $this->ruleName,
            'passed' => $this->passed,
            'actual_value' => $this->actualValue,
            'expected_value' => $this->expectedValue,
            'context' => $this->contextDescription,
            'metadata' => $this->metadata,
        ];
    }
}






