<?php

declare(strict_types=1);

namespace App\TradeEntry\Message;

/**
 * Message pour monitorer une position (SL/TP) via ws-worker
 */
final class MonitorPositionMessage
{
    public function __construct(
        public readonly string $positionId,
        public readonly string $symbol,
        public readonly string $orderId,
        public readonly ?float $stopLoss = null,
        public readonly ?float $takeProfit = null,
    ) {}
}
