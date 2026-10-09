<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;

/** Content identity for the local research-plan arithmetic and protocol code. */
final class ResearchCodeIdentity
{
    private const FILES = [
        'Command/ResearchPlanWorkerCommand.php',
        'TradingCore/Backtesting/Research/ResearchCodeIdentity.php',
        'TradingCore/Backtesting/Research/ResearchVariant.php',
        'TradingCore/Backtesting/Research/ResearchInstrumentAssumptions.php',
        'TradingCore/Backtesting/Research/ResearchCostAssumptions.php',
        'TradingCore/Backtesting/Research/ResearchPlanBuilder.php',
        'TradingCore/Backtesting/Research/ResearchPlanSession.php',
        'TradingCore/OrderPlan/Canonical/EntryZonePriceMath.php',
        'TradingCore/OrderPlan/Canonical/ProtectionPriceMath.php',
        'TradingCore/OrderPlan/Canonical/NetRCostMath.php',
        'TradingCore/OrderPlan/Canonical/CanonicalExecutionPolicy.php',
        'TradingCore/OrderPlan/Canonical/CanonicalHoldingBoundary.php',
        'TradingCore/Risk/Canonical/CanonicalRiskEngine.php',
        'TradingCore/Risk/Canonical/CanonicalRiskCalculationRequest.php',
        'TradingCore/Risk/Canonical/CanonicalCostSnapshot.php',
        'TradingCore/Risk/Canonical/CanonicalRiskPolicy.php',
        'TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioDecimal.php',
        'TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioPolicy.php',
    ];

    public static function current(): string
    {
        $sourceRoot = dirname(__DIR__, 3);
        $digests = [];
        foreach (self::FILES as $relative) {
            $digest = hash_file('sha256', $sourceRoot . '/' . $relative);
            if ($digest === false) {
                throw new \InvalidArgumentException('research_plan_code_file_missing');
            }
            $digests[$relative] = $digest;
        }
        return CanonicalBacktestRuleEvaluator::canonicalHash($digests);
    }
}
