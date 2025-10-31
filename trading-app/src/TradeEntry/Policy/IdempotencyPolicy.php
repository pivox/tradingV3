<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

final class IdempotencyPolicy
{
    public function __construct() {}

    public function newClientOrderId(): string
    {
        // Bitmart requires client_order_id to be alphanumeric (max 32 chars)
        // Generate an uppercase base36 + hex combo, no separators
        $randBase36 = strtoupper(base_convert((string) random_int(1, PHP_INT_MAX), 10, 36));
        $randHex = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
        $id = 'CLI' . $randBase36 . $randHex;
        return substr($id, 0, 32);
    }
}
