<?php

declare(strict_types=1);

namespace App\Trading\Lineage\Persistence;

use App\Entity\FuturesOrder;
use App\Provider\Context\ExchangeContext;

interface FuturesOrderRecoverySource
{
    public function findOneByOrderId(string $orderId, ?ExchangeContext $context = null): ?FuturesOrder;
    public function findOneByClientOrderId(string $clientOrderId, ?ExchangeContext $context = null): ?FuturesOrder;
}
