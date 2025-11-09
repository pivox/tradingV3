<?php

declare(strict_types=1);

namespace App\MtfValidator\Validator;

/**
 * DTO représentant le résultat complet de la validation
 */
final class ValidationResult
{
    /** @var ValidationError[] */
    private array $errors = [];

    /** @var ValidationError[] */
    private array $warnings = [];

    public function addError(ValidationError $error): void
    {
        $this->errors[] = $error;
    }

    public function addWarning(ValidationError $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * @return ValidationError[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return ValidationError[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    public function isValid(): bool
    {
        return !$this->hasErrors();
    }

    public function getErrorCount(): int
    {
        return count($this->errors);
    }

    public function getWarningCount(): int
    {
        return count($this->warnings);
    }

    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'errors' => array_map(fn(ValidationError $e) => $e->toArray(), $this->errors),
            'warnings' => array_map(fn(ValidationError $e) => $e->toArray(), $this->warnings),
            'error_count' => $this->getErrorCount(),
            'warning_count' => $this->getWarningCount(),
        ];
    }
}

