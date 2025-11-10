<?php

declare(strict_types=1);

namespace App\MtfValidator\Validator\Functional;

/**
 * DTO représentant le résultat d'un test de scénario
 */
final class ScenarioTestResult
{
    /** @var RuleTestResult[] */
    private array $ruleResults = [];

    public function __construct(
        private readonly string $scenarioName,
        private readonly bool $passed,
        private readonly string $timeframe,
        private readonly string $side,
        private readonly ?string $executionTf = null,
        private readonly ?string $expectedExecutionTf = null,
        private readonly string $message = ''
    ) {
    }

    public function addRuleResult(RuleTestResult $result): void
    {
        $this->ruleResults[] = $result;
    }

    public function getScenarioName(): string
    {
        return $this->scenarioName;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function getTimeframe(): string
    {
        return $this->timeframe;
    }

    public function getSide(): string
    {
        return $this->side;
    }

    public function getExecutionTf(): ?string
    {
        return $this->executionTf;
    }

    public function getExpectedExecutionTf(): ?string
    {
        return $this->expectedExecutionTf;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return RuleTestResult[]
     */
    public function getRuleResults(): array
    {
        return $this->ruleResults;
    }

    public function toArray(): array
    {
        return [
            'scenario' => $this->scenarioName,
            'passed' => $this->passed,
            'timeframe' => $this->timeframe,
            'side' => $this->side,
            'execution_tf' => $this->executionTf,
            'expected_execution_tf' => $this->expectedExecutionTf,
            'message' => $this->message,
            'rule_results' => array_map(fn($r) => $r->toArray(), $this->ruleResults),
        ];
    }
}






