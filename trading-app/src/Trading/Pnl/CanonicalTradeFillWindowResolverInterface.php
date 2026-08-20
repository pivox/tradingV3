<?php

declare(strict_types=1);

namespace App\Trading\Pnl;

interface CanonicalTradeFillWindowResolverInterface
{
    public function resolve(string $internalTradeId, string $exchange, string $marketType): ?CanonicalTradeFillWindow;
}
