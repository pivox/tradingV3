<?php
declare(strict_types=1);

namespace App\TradeEntry\Message;

final class OutOfZoneWatchMessage
{
    /**
     * @param string $watchId Identifiant unique du watch (pour idempotence côté JS)
     * @param string $traceId Trace ID original (decision_key)
     * @param string $symbol Symbole du contrat (ex: BTCUSDT)
     * @param string $side Direction ("long" | "short")
     * @param float $zoneMin Prix minimum de la zone d'entrée
     * @param float $zoneMax Prix maximum de la zone d'entrée
     * @param int $ttlSec Durée de vie du watch en secondes (ex: 300)
     * @param bool $dryRun Mode dry-run (true = ne pas placer d'ordre réel)
     * @param array<string,mixed> $executePayload Payload complet prêt à être forwardé à /api/trade-entry/execute
     */
    public function __construct(
        public readonly string $watchId,
        public readonly string $traceId,
        public readonly string $symbol,
        public readonly string $side,
        public readonly float $zoneMin,
        public readonly float $zoneMax,
        public readonly int $ttlSec,
        public readonly bool $dryRun,
        public readonly array $executePayload,
    ) {}
}

