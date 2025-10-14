<?php

declare(strict_types=1);

namespace App\Domain\PostValidation\Dto;

/**
 * DTO représentant un plan d'ordres pour l'ouverture de position
 */
final class OrderPlanDto
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $side, // LONG | SHORT
        public readonly string $executionTimeframe, // 1m | 5m
        public readonly float $quantity,
        public readonly float $leverage,
        public readonly array $makerOrders, // Ordres LIMIT GTC
        public readonly array $fallbackOrders, // Ordres IOC/MARKET
        public readonly array $tpSlOrders, // Ordres TP/SL
        public readonly string $clientOrderId,
        public readonly string $decisionKey,
        public readonly array $riskMetrics,
        public readonly array $evidence,
        public readonly int $timestamp
    ) {
    }

    public function getTotalNotional(): float
    {
        return $this->quantity * $this->getEntryPrice() * $this->leverage;
    }

    public function getEntryPrice(): float
    {
        // Prix d'entrée estimé basé sur les ordres maker
        if (!empty($this->makerOrders)) {
            $firstOrder = $this->makerOrders[0];
            return $firstOrder['price'] ?? 0.0;
        }
        return 0.0;
    }

    public function getRiskAmount(): float
    {
        return $this->riskMetrics['risk_amount'] ?? 0.0;
    }

    public function getStopLossPrice(): float
    {
        return $this->riskMetrics['stop_loss_price'] ?? 0.0;
    }

    public function getTakeProfitPrice(): float
    {
        return $this->riskMetrics['take_profit_price'] ?? 0.0;
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'side' => $this->side,
            'execution_timeframe' => $this->executionTimeframe,
            'quantity' => $this->quantity,
            'leverage' => $this->leverage,
            'total_notional' => $this->getTotalNotional(),
            'entry_price' => $this->getEntryPrice(),
            'risk_amount' => $this->getRiskAmount(),
            'stop_loss_price' => $this->getStopLossPrice(),
            'take_profit_price' => $this->getTakeProfitPrice(),
            'maker_orders' => $this->makerOrders,
            'fallback_orders' => $this->fallbackOrders,
            'tp_sl_orders' => $this->tpSlOrders,
            'client_order_id' => $this->clientOrderId,
            'decision_key' => $this->decisionKey,
            'risk_metrics' => $this->riskMetrics,
            'evidence' => $this->evidence,
            'timestamp' => $this->timestamp
        ];
    }
}

