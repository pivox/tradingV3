<?php
// src/Service/Infra/Idempotency/ClientOrderIdFactory.php
declare(strict_types=1);

namespace App\Service\Trading\Idempotency;

final class ClientOrderIdFactory
{
    public function make(
        string $kind,        // 'tp' | 'sl'
        string $symbol,      // 'BTCUSDT'
        string $side,        // 'long' | 'short'
        ?string $positionId, // optionnel
        int $bucketSeconds = 3600
    ): string {
        $bucket = (int)(time() / $bucketSeconds) * $bucketSeconds;
        $raw = sprintf('bm:%s:%s:%s:%s:%d',
            $kind, strtoupper($symbol), strtolower($side), $positionId ?: 'npos', $bucket
        );
        return substr(preg_replace('/[^A-Za-z0-9:\-_]/', '', $raw), 0, 48);
    }
}
