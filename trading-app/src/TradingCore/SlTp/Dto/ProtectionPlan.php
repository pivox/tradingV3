<?php
declare(strict_types=1);

namespace App\TradingCore\SlTp\Dto;

use App\TradingCore\SlTp\Enum\ProtectionPlanStatus;

final readonly class ProtectionPlan
{
    /**
     * @param list<string> $invalidReasons
     * @param list<string> $warnings
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public ?StopLossResult $stopLoss,
        public ?TakeProfitResult $takeProfit,
        public ?LiquidationCheckResult $liquidationCheck,
        public bool $isValid,
        public ProtectionPlanStatus $status,
        public array $invalidReasons = [],
        public array $warnings = [],
        public array $metadata = [],
    ) {}
}
