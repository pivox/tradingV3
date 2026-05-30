<?php

declare(strict_types=1);

namespace App\Exchange\Contract;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

interface ExchangeAdapterRegistryInterface
{
    public function get(Exchange $exchange, MarketType $marketType): ExchangeAdapterInterface;

    /**
     * @return ExchangeAdapterInterface[]
     */
    public function all(): array;
}
