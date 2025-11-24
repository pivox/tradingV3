<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Rule;

interface RuleEngineInterface
{
    /**
     * @param array<string,mixed>|string $block
     * @param array<string,mixed>        $rulesConfig
     * @param array<string,mixed>        $indicators
     */
    public function evaluate(
        array|string $block,
        array $rulesConfig,
        array $indicators,
        string $timeframe
    ): bool;

    /**
     * @param array<string,mixed> $rulesConfig
     * @param array<string,mixed> $overrides
     * @param array<string,mixed> $indicators
     */
    public function evaluateNamedRule(
        string $ruleName,
        array $rulesConfig,
        array $overrides,
        array $indicators,
        string $timeframe
    ): bool;
}
