<?php

declare(strict_types=1);

namespace App\MtfValidator\Validator\Functional;

/**
 * DTO représentant un scénario de test fonctionnel
 */
final class TestScenario
{
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly string $timeframe,
        private readonly string $side,
        private readonly array $context,
        private readonly array $expectedResults = [],
        private readonly ?string $expectedExecutionTf = null
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getTimeframe(): string
    {
        return $this->timeframe;
    }

    public function getSide(): string
    {
        return $this->side;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getExpectedResults(): array
    {
        return $this->expectedResults;
    }

    public function getExpectedExecutionTf(): ?string
    {
        return $this->expectedExecutionTf;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'timeframe' => $this->timeframe,
            'side' => $this->side,
            'expected_results' => $this->expectedResults,
            'expected_execution_tf' => $this->expectedExecutionTf,
        ];
    }
}


