<?php

declare(strict_types=1);

namespace App\TradeEntry\Message;

/**
 * Message pour placer un ordre via ws-worker avec entry zone monitoring
 */
final class PlaceOrderMessage
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $symbol,
        public readonly string $side,
        public readonly float $entryZoneMin,
        public readonly float $entryZoneMax,
        public readonly float $quantity,
        public readonly ?int $leverage = null,
        public readonly ?float $stopLoss = null,
        public readonly ?float $takeProfit = null,
        public readonly int $timeoutSeconds = 300,
        public readonly array $metadata = [],
    ) {}
}
