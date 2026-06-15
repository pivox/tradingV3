<?php
declare(strict_types=1);

namespace App\TradingCore\SlTp\Service;

use App\TradingCore\SlTp\Dto\LiquidationCheckResult;
use App\TradingCore\SlTp\Dto\ProtectionPlan;
use App\TradingCore\SlTp\Dto\StopLossResult;
use App\TradingCore\SlTp\Dto\TakeProfitResult;
use App\TradingCore\SlTp\Enum\ProtectionPlanStatus;

final class ProtectionPlanValidator
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function validate(
        ?StopLossResult $stopLoss,
        ?TakeProfitResult $takeProfit,
        ?LiquidationCheckResult $liquidationCheck,
        array $metadata = [],
    ): ProtectionPlan {
        $invalidReasons = [];
        $warnings = [];

        if (!$stopLoss instanceof StopLossResult) {
            $invalidReasons[] = 'stop_loss_missing';
        } else {
            if (!$stopLoss->isFullSize) {
                $invalidReasons[] = 'stop_loss_not_full_size';
            }
            if ($stopLoss->stopPct <= 0.0 || !\is_finite($stopLoss->stopPct)) {
                $invalidReasons[] = 'stop_pct_not_positive';
            }
        }

        if ($liquidationCheck instanceof LiquidationCheckResult && !$liquidationCheck->isSafe) {
            $invalidReasons[] = 'liquidation_guard_unsafe';
        }

        if ($takeProfit instanceof TakeProfitResult && $takeProfit->expectedNetR !== null && $takeProfit->expectedNetR <= 0.0) {
            $warnings[] = 'take_profit_net_r_not_positive';
        }

        $isValid = $invalidReasons === [];

        return new ProtectionPlan(
            stopLoss: $stopLoss,
            takeProfit: $takeProfit,
            liquidationCheck: $liquidationCheck,
            isValid: $isValid,
            status: $isValid ? ProtectionPlanStatus::Valid : ProtectionPlanStatus::Invalid,
            invalidReasons: $invalidReasons,
            warnings: $warnings,
            metadata: $metadata,
        );
    }
}
