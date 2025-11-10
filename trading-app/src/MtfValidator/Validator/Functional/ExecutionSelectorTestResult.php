<?php

declare(strict_types=1);

namespace App\MtfValidator\Validator\Functional;

/**
 * DTO représentant le résultat d'un test du sélecteur d'exécution
 */
final class ExecutionSelectorTestResult
{
    public function __construct(
        private readonly string $testName,
        private readonly bool $passed,
        private readonly string $expectedTf,
        private readonly string $actualTf,
        private readonly array $context,
        private readonly array $evaluationDetails = [],
        private readonly string $message = ''
    ) {
    }

    public function getTestName(): string
    {
        return $this->testName;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function getExpectedTf(): string
    {
        return $this->expectedTf;
    }

    public function getActualTf(): string
    {
        return $this->actualTf;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getEvaluationDetails(): array
    {
        return $this->evaluationDetails;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function toArray(): array
    {
        return [
            'test' => $this->testName,
            'passed' => $this->passed,
            'expected_tf' => $this->expectedTf,
            'actual_tf' => $this->actualTf,
            'context' => $this->context,
            'evaluation_details' => $this->evaluationDetails,
            'message' => $this->message,
        ];
    }
}






