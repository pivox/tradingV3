<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Indicator;

interface CanonicalIndicatorProjectorInterface
{
    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    public function project(#[\SensitiveParameter] array $request): array;
}
