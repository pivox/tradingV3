<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\Order;

use App\Service\Trading\Opening\Sizing\SizingDecision;
use RuntimeException;

final class MarketOrderBuilder
{
    public function build(
        string $symbol,
        string $side,
        string $openType,
        SizingDecision $decision
    ): OrderDraft {
        $clientOrderId = $this->generateClientOrderId();
        $payload = [
            'symbol' => $symbol,
            'client_order_id' => $clientOrderId,
            'side' => $this->mapSideOpen($side),
            'mode' => 1,
            'type' => 'market',
            'open_type' => $openType,
            'size' => $decision->contracts,
            'preset_take_profit_price_type' => -2,
            'preset_stop_loss_price_type' => -2,
            'preset_take_profit_price' => (string)$decision->takeProfit,
            'preset_stop_loss_price' => (string)$decision->stopLoss,
        ];

        return new OrderDraft($clientOrderId, $payload);
    }

    private function generateClientOrderId(): string
    {
        try {
            return 'SF_' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            throw new RuntimeException('Unable to generate client_order_id: ' . $e->getMessage(), 0, $e);
        }
    }

    private function mapSideOpen(string $side): int
    {
        return match (strtolower($side)) {
            'long', 'buy' => 1,
            'short', 'sell' => 4,
            default => throw new RuntimeException('side invalide: ' . $side),
        };
    }
}
