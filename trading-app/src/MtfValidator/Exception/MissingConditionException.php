<?php

declare(strict_types=1);

namespace App\MtfValidator\Exception;

final class MissingConditionException extends \RuntimeException
{
    public static function forRule(string $conditionName, ?string $ruleName = null): self
    {
        $suffix = $ruleName ? sprintf(' (référencée par la règle "%s")', $ruleName) : '';
        $message = sprintf('Condition "%s" introuvable%s. Vérifie tes validations YAML ou implémente la condition PHP correspondante.', $conditionName, $suffix);

        return new self($message);
    }
}
