<?php

declare(strict_types=1);

namespace App\MtfValidator\Validator\Functional;

/**
 * DTO représentant un problème de cohérence logique
 */
final class LogicalConsistencyIssue
{
    public function __construct(
        private readonly string $type,
        private readonly string $severity,
        private readonly string $message,
        private readonly array $affectedRules = [],
        private readonly array $details = []
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getAffectedRules(): array
    {
        return $this->affectedRules;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'severity' => $this->severity,
            'message' => $this->message,
            'affected_rules' => $this->affectedRules,
            'details' => $this->details,
        ];
    }
}






