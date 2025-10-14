<?php

declare(strict_types=1);

namespace App\Domain\Position\Dto;

use App\Domain\Common\Enum\SignalSide;

class PositionOpeningDto
{
    public function __construct(
        public readonly string $symbol,
        public readonly SignalSide $side,
        public readonly float $leverage,
        public readonly float $positionSize,
        public readonly float $entryPrice,
        public readonly float $stopLossPrice,
        public readonly float $takeProfitPrice,
        public readonly float $riskAmount,
        public readonly float $potentialProfit,
        public readonly array $riskMetrics,
        public readonly array $executionParams
    ) {
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'side' => $this->side->value,
            'leverage' => $this->leverage,
            'position_size' => $this->positionSize,
            'entry_price' => $this->entryPrice,
            'stop_loss_price' => $this->stopLossPrice,
            'take_profit_price' => $this->takeProfitPrice,
            'risk_amount' => $this->riskAmount,
            'potential_profit' => $this->potentialProfit,
            'risk_metrics' => $this->riskMetrics,
            'execution_params' => $this->executionParams
        ];
    }
}




