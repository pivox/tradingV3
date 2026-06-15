<?php
declare(strict_types=1);

namespace App\TradingCore\Risk\Enum;

enum RiskSource: string
{
    case FixedRiskPct = 'fixed_risk_pct';
    case RiskPctPercentLegacy = 'risk_pct_percent_legacy';
    case RequestRiskPct = 'request_risk_pct';
}
