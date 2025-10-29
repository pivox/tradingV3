<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

final class IdempotencyPolicy
{
    public function __construct() {}

    public function newClientOrderId(): string
    {
        return 'cli_' . dechex(random_int(1, PHP_INT_MAX));
    }
}
