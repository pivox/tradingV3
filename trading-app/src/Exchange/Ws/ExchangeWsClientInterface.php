<?php

declare(strict_types=1);

namespace App\Exchange\Ws;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

interface ExchangeWsClientInterface
{
    public function exchange(): Exchange;

    public function marketType(): MarketType;

    /**
     * @return iterable<mixed>
     */
    public function drainPrivateEvents(?string $symbol = null): iterable;
}
