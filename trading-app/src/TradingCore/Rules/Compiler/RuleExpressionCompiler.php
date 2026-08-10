<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Compiler;

use App\TradingCore\Rules\Ast\AllOfNode;
use App\TradingCore\Rules\Ast\AnyOfNode;
use App\TradingCore\Rules\Ast\ConditionNode;
use App\TradingCore\Rules\Ast\RuleNode;
use App\TradingCore\Rules\Catalog\ConditionCatalog;
use App\TradingCore\Rules\Catalog\ConditionCatalogException;
use App\TradingCore\Rules\Catalog\ConditionParameterDefinition;

final readonly class RuleExpressionCompiler
{
    public function __construct(private ConditionCatalog $catalog)
    {
    }

    /** @param array<string, mixed> $expression */
    public function compile(array $expression, string $side): RuleNode
    {
        if (!in_array($side, ['long', 'short'], true)) {
            throw new RuleCompilationException(sprintf('Unknown side "%s".', $side));
        }
        if (array_key_exists('op', $expression)) {
            return $this->compileGroup($expression, $side);
        }

        return $this->compileCondition($expression, $side);
    }

    /** @param array<string, mixed> $expression */
    private function compileGroup(array $expression, string $side): RuleNode
    {
        $this->exactKeys($expression, ['op', 'nodes', 'provenance'], 'group');
        $operator = $expression['op'];
        if (!is_string($operator) || !in_array($operator, ['all_of', 'any_of'], true)) {
            throw new RuleCompilationException(sprintf('Unknown operator "%s".', is_scalar($operator) ? (string) $operator : get_debug_type($operator)));
        }
        $nodes = $expression['nodes'];
        if (!is_array($nodes) || !array_is_list($nodes) || $nodes === []) {
            throw new RuleCompilationException(sprintf('%s group must not be empty.', $operator));
        }
        $provenance = $this->nonEmptyString($expression['provenance'], 'group.provenance');
        $children = [];
        foreach ($nodes as $index => $node) {
            if (!is_array($node) || array_is_list($node)) {
                throw new RuleCompilationException(sprintf('group.nodes[%d] must be an expression mapping.', $index));
            }
            $children[] = $this->compile($node, $side);
        }

        return $operator === 'all_of'
            ? new AllOfNode($children, $provenance)
            : new AnyOfNode($children, $provenance);
    }

    /** @param array<string, mixed> $expression */
    private function compileCondition(array $expression, string $side): ConditionNode
    {
        $allowed = array_key_exists('parameters', $expression)
            ? ['condition', 'timeframe', 'parameters', 'provenance']
            : ['condition', 'timeframe', 'provenance'];
        $this->exactKeys($expression, $allowed, 'condition');
        $conditionId = $this->nonEmptyString($expression['condition'], 'condition.condition');
        $timeframe = $this->nonEmptyString($expression['timeframe'], 'condition.timeframe');
        $provenance = $this->nonEmptyString($expression['provenance'], 'condition.provenance');
        try {
            $definition = $this->catalog->definition($conditionId);
        } catch (ConditionCatalogException $exception) {
            throw new RuleCompilationException($exception->getMessage(), previous: $exception);
        }
        if (!in_array($side, $definition->sides, true)) {
            throw new RuleCompilationException(sprintf('Condition "%s" is not compatible with side %s.', $conditionId, $side));
        }
        if (!in_array($timeframe, $definition->timeframes, true)) {
            throw new RuleCompilationException(sprintf('Condition "%s" is not compatible with timeframe %s.', $conditionId, $timeframe));
        }
        $provided = $expression['parameters'] ?? [];
        if (!is_array($provided) || ($provided !== [] && array_is_list($provided))) {
            throw new RuleCompilationException(sprintf('Parameters for condition "%s" must be a mapping.', $conditionId));
        }
        $unknown = array_diff(array_keys($provided), array_keys($definition->parameters));
        if ($unknown !== []) {
            throw new RuleCompilationException(sprintf('Unknown parameter "%s" for condition "%s".', reset($unknown), $conditionId));
        }
        $parameters = [];
        foreach ($definition->parameters as $name => $parameter) {
            if (!array_key_exists($name, $provided)) {
                if ($parameter->required) {
                    throw new RuleCompilationException(sprintf('Missing required parameter "%s" for condition "%s".', $name, $conditionId));
                }
                $parameters[$name] = $parameter->default;
                continue;
            }
            $parameters[$name] = $this->validateParameter($provided[$name], $parameter, $name, $conditionId);
        }
        ksort($parameters, SORT_STRING);

        /** @var array<string, bool|int|float|string> $parameters */
        return new ConditionNode($conditionId, $timeframe, $side, $parameters, $provenance);
    }

    private function validateParameter(mixed $value, ConditionParameterDefinition $definition, string $name, string $conditionId): bool|int|float|string
    {
        $valid = match ($definition->type) {
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'integer' => is_int($value),
            'string' => is_string($value) && trim($value) !== '',
            'boolean' => is_bool($value),
            default => false,
        };
        if (!$valid) {
            $expected = $definition->type === 'number' ? 'finite number' : $definition->type;
            throw new RuleCompilationException(sprintf('Parameter "%s" for condition "%s" must be a %s.', $name, $conditionId, $expected));
        }
        if ((is_int($value) || is_float($value)) && $definition->min !== null && $value < $definition->min) {
            throw new RuleCompilationException(sprintf('Parameter "%s" for condition "%s" is below minimum.', $name, $conditionId));
        }
        if ((is_int($value) || is_float($value)) && $definition->max !== null && $value > $definition->max) {
            throw new RuleCompilationException(sprintf('Parameter "%s" for condition "%s" is above maximum.', $name, $conditionId));
        }
        if ($definition->values !== [] && !in_array($value, $definition->values, true)) {
            throw new RuleCompilationException(sprintf('Parameter "%s" for condition "%s" is outside its enum.', $name, $conditionId));
        }

        /** @var bool|int|float|string $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $keys
     */
    private function exactKeys(array $value, array $keys, string $path): void
    {
        $unknown = array_diff(array_keys($value), $keys);
        if ($unknown !== []) {
            throw new RuleCompilationException(sprintf('Unknown field "%s" in %s.', reset($unknown), $path));
        }
        $missing = array_diff($keys, array_keys($value));
        if ($missing !== []) {
            throw new RuleCompilationException(sprintf('%s is missing field "%s".', $path, reset($missing)));
        }
    }

    private function nonEmptyString(mixed $value, string $path): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new RuleCompilationException($path . ' must be a non-empty string.');
        }

        return $value;
    }
}
