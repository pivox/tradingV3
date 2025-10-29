<?php
declare(strict_types=1);

namespace App\TradeEntry\Service\Leverage;

use App\Contract\EntryTrade\LeverageServiceInterface;

final class DefaultLeverageService implements LeverageServiceInterface
{
    public function computeLeverage(
        string $symbol,
        float $entryPrice,
        float $contractSize,
        int $positionSize,
        float $budgetUsdt,
        float $availableUsdt,
        int $minLeverage,
        int $maxLeverage
    ): int {
        $effectiveBudget = min(max($budgetUsdt, 0.0), max($availableUsdt, 0.0));
        if ($effectiveBudget <= 0.0) {
            throw new \RuntimeException('Budget indisponible pour calculer le levier');
        }

        $notional = $entryPrice * $contractSize * $positionSize;
        if ($notional <= 0.0) {
            return max(1, $minLeverage);
        }

        $raw = (int)ceil($notional / max($effectiveBudget, 1e-9));
        $clamped = max(1, max($minLeverage, $raw));

        return (int)min($maxLeverage, $clamped);
    }
}
