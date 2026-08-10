<?php

declare(strict_types=1);

namespace App\Trading\Lineage\Persistence;

use App\Entity\OrderIntent;
use App\Provider\Context\ExchangeContext;

interface OrderIntentRecoverySource
{
    public function findOneByClientOrderId(string $clientOrderId, ?ExchangeContext $context = null): ?OrderIntent;

    public function findOneByOrderId(string $orderId, ?ExchangeContext $context = null): ?OrderIntent;

    /** @return list<OrderIntent> */
    public function findByOrderIdForRecovery(string $orderId, ?ExchangeContext $context = null): array;
}
