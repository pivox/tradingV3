<?php
declare(strict_types=1);

namespace App\Contract\EntryTrade;

interface LeverageServiceInterface
{
    public function computeLeverage(
        string $symbol,
        float $entryPrice,
        float $contractSize,
        int $positionSize,
        float $budgetUsdt,
        float $availableUsdt,
        int $minLeverage,
        int $maxLeverage,
        ?float $stopPct = null,
        ?float $atr5mValue = null,
        ?string $executionTf = null
    ): int;
}
