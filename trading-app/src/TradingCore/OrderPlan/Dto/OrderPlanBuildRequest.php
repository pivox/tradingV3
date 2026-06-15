<?php
declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Dto;

use App\TradingCore\Decision\Dto\TradeCandidate;
use App\TradingCore\Entry\Dto\EntryZone;
use App\TradingCore\Risk\Dto\LeverageCalculationResult;
use App\TradingCore\Risk\Dto\RiskCalculationResult;
use App\TradingCore\SlTp\Dto\ProtectionPlan;

final readonly class OrderPlanBuildRequest
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public TradeCandidate $candidate,
        public ?EntryZone $entryZone,
        public ?RiskCalculationResult $riskCalculation,
        public ?LeverageCalculationResult $leverageCalculation,
        public ?ProtectionPlan $protectionPlan,
        public string $orderType,
        public string $marginMode,
        public string $timeInForce,
        public ?string $clientOrderId = null,
        public ?string $idempotencyKey = null,
        public array $metadata = [],
    ) {
    }
}
