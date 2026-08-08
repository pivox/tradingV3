<?php

declare(strict_types=1);

namespace App\Trading\Lineage\Persistence;

use App\Entity\FuturesOrderTrade;
use App\Provider\Context\ExchangeContext;

interface FuturesOrderTradeRecoverySource
{
    public function findOneByTradeId(string $tradeId, ?ExchangeContext $context = null): ?FuturesOrderTrade;
}
