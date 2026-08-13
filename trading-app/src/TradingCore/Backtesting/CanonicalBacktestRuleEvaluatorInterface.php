<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting;

interface CanonicalBacktestRuleEvaluatorInterface
{
    /** @param array<string,mixed> $request */
    public function evaluate(#[\SensitiveParameter] array $request): CanonicalBacktestRuleEvaluation;
}
