<?php

declare(strict_types=1);

namespace App\MtfValidator\Validator\Functional;

/**
 * DTO représentant le résultat complet de la validation fonctionnelle
 */
final class FunctionalValidationResult
{
    /** @var RuleTestResult[] */
    private array $ruleResults = [];

    /** @var ScenarioTestResult[] */
    private array $scenarioResults = [];

    /** @var ExecutionSelectorTestResult[] */
    private array $executionSelectorResults = [];

    /** @var LogicalConsistencyIssue[] */
    private array $consistencyIssues = [];

    private int $totalRulesTested = 0;
    private int $totalRulesPassed = 0;
    private int $totalScenariosTested = 0;
    private int $totalScenariosPassed = 0;

    public function addRuleResult(RuleTestResult $result): void
    {
        $this->ruleResults[] = $result;
        $this->totalRulesTested++;
        if ($result->isPassed()) {
            $this->totalRulesPassed++;
        }
    }

    public function addScenarioResult(ScenarioTestResult $result): void
    {
        $this->scenarioResults[] = $result;
        $this->totalScenariosTested++;
        if ($result->isPassed()) {
            $this->totalScenariosPassed++;
        }
    }

    public function addExecutionSelectorResult(ExecutionSelectorTestResult $result): void
    {
        $this->executionSelectorResults[] = $result;
    }

    public function addConsistencyIssue(LogicalConsistencyIssue $issue): void
    {
        $this->consistencyIssues[] = $issue;
    }

    /**
     * @return RuleTestResult[]
     */
    public function getRuleResults(): array
    {
        return $this->ruleResults;
    }

    /**
     * @return ScenarioTestResult[]
     */
    public function getScenarioResults(): array
    {
        return $this->scenarioResults;
    }

    /**
     * @return ExecutionSelectorTestResult[]
     */
    public function getExecutionSelectorResults(): array
    {
        return $this->executionSelectorResults;
    }

    /**
     * @return LogicalConsistencyIssue[]
     */
    public function getConsistencyIssues(): array
    {
        return $this->consistencyIssues;
    }

    public function getTotalRulesTested(): int
    {
        return $this->totalRulesTested;
    }

    public function getTotalRulesPassed(): int
    {
        return $this->totalRulesPassed;
    }

    public function getTotalScenariosTested(): int
    {
        return $this->totalScenariosTested;
    }

    public function getTotalScenariosPassed(): int
    {
        return $this->totalScenariosPassed;
    }

    public function hasConsistencyIssues(): bool
    {
        return !empty($this->consistencyIssues);
    }

    public function getSuccessRate(): float
    {
        if ($this->totalRulesTested === 0) {
            return 0.0;
        }
        return ($this->totalRulesPassed / $this->totalRulesTested) * 100.0;
    }

    public function getScenarioSuccessRate(): float
    {
        if ($this->totalScenariosTested === 0) {
            return 0.0;
        }
        return ($this->totalScenariosPassed / $this->totalScenariosTested) * 100.0;
    }

    public function toArray(): array
    {
        return [
            'summary' => [
                'rules' => [
                    'tested' => $this->totalRulesTested,
                    'passed' => $this->totalRulesPassed,
                    'failed' => $this->totalRulesTested - $this->totalRulesPassed,
                    'success_rate' => round($this->getSuccessRate(), 2),
                ],
                'scenarios' => [
                    'tested' => $this->totalScenariosTested,
                    'passed' => $this->totalScenariosPassed,
                    'failed' => $this->totalScenariosTested - $this->totalScenariosPassed,
                    'success_rate' => round($this->getScenarioSuccessRate(), 2),
                ],
                'consistency_issues' => count($this->consistencyIssues),
            ],
            'rule_results' => array_map(fn($r) => $r->toArray(), $this->ruleResults),
            'scenario_results' => array_map(fn($r) => $r->toArray(), $this->scenarioResults),
            'execution_selector_results' => array_map(fn($r) => $r->toArray(), $this->executionSelectorResults),
            'consistency_issues' => array_map(fn($i) => $i->toArray(), $this->consistencyIssues),
        ];
    }
}






