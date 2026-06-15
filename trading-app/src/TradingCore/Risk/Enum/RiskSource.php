<?php
declare(strict_types=1);

namespace App\TradingCore\Risk\Enum;

enum RiskSource: string
{
    case FixedRiskPct = 'fixed_risk_pct';
    case RiskPctPercentLegacy = 'risk_pct_percent_legacy';
    // Reserved for per-request override (not wired in PR07 — no handler in PositionSizer yet).
    case RequestRiskPct = 'request_risk_pct';
}
