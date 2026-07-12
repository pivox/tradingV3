<?php
declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Dto;

use App\TradingCore\Entry\Dto\EntryZone;
use App\TradingCore\OrderPlan\Enum\OrderPlanStatus;
use App\TradingCore\Risk\Dto\LeverageCalculationResult;
use App\TradingCore\Risk\Dto\RiskCalculationResult;
use App\TradingCore\SlTp\Dto\ProtectionPlan;

final readonly class OrderPlan
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $symbol,
        public string $profile,
        public string $exchange,
        public string $marketType,
        public string $side,
        public string $orderType,
        public string $marginMode,
        public string $timeInForce,
        public float $entryPrice,
        public float $quantity,
        public int $leverage,
        public ?ProtectionPlan $protectionPlan = null,
        public ?string $clientOrderId = null,
        public ?string $idempotencyKey = null,
        public ?string $decisionKey = null,
        public ?EntryZone $entryZone = null,
        public ?RiskCalculationResult $riskCalculation = null,
        public ?LeverageCalculationResult $leverageCalculation = null,
        public ?int $pricePrecision = null,
        public ?float $contractSize = null,
        ?OrderPlanValidationResult $validation = null,
        public array $metadata = [],
        ?string $instrument = null,
        public ?string $configHash = null,
    ) {
        $this->instrument = $instrument ?? $symbol;
        $this->validation = $validation ?? new OrderPlanValidationResult(
            status: OrderPlanStatus::Invalid,
            isExecutable: false,
            invalidReasons: ['order_plan_not_validated'],
        );
    }

    public string $instrument;

    public OrderPlanValidationResult $validation;

    public function withValidation(OrderPlanValidationResult $validation): self
    {
        return new self(
            symbol: $this->symbol,
            profile: $this->profile,
            exchange: $this->exchange,
            marketType: $this->marketType,
            side: $this->side,
            orderType: $this->orderType,
            marginMode: $this->marginMode,
            timeInForce: $this->timeInForce,
            entryPrice: $this->entryPrice,
            quantity: $this->quantity,
            leverage: $this->leverage,
            protectionPlan: $this->protectionPlan,
            clientOrderId: $this->clientOrderId,
            idempotencyKey: $this->idempotencyKey,
            decisionKey: $this->decisionKey,
            entryZone: $this->entryZone,
            riskCalculation: $this->riskCalculation,
            leverageCalculation: $this->leverageCalculation,
            pricePrecision: $this->pricePrecision,
            contractSize: $this->contractSize,
            validation: $validation,
            metadata: $this->metadata,
            instrument: $this->instrument,
            configHash: $this->configHash,
        );
    }
}
