<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Service;

final class ClientOrderIdFactory
{
    public function fromIdempotencyKey(string $idempotencyKey): string
    {
        $normalized = trim($idempotencyKey);
        if ($normalized === '') {
            throw new \InvalidArgumentException('Idempotency key is required to build a client order id.');
        }

        return 'CID' . substr(strtoupper(hash('sha256', $normalized)), 0, 29);
    }
}
