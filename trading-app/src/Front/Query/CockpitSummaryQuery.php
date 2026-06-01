<?php

declare(strict_types=1);

namespace App\Front\Query;

use App\Front\ViewModel\CockpitSummaryView;

final readonly class CockpitSummaryQuery
{
    public function __construct(
        private RiskSummaryQuery $riskSummaryQuery,
        private DecisionSummaryQuery $decisionSummaryQuery,
        private SystemHealthQuery $systemHealthQuery,
        private ConfigSummaryQuery $configSummaryQuery,
    ) {
    }

    public function summary(): CockpitSummaryView
    {
        return new CockpitSummaryView(
            risk: $this->riskSummaryQuery->getSummary(),
            decisions: $this->decisionSummaryQuery->latest(5, 80),
            mode: $this->configSummaryQuery->activeMode(),
            system: $this->systemHealthQuery->health(),
        );
    }
}
