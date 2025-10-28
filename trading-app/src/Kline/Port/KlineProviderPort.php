<?php

declare(strict_types=1);

namespace App\Kline\Port;

use App\Common\Enum\Timeframe;

/**
 * Port pour le provider de klines
 * Interface temporaire pour permettre au système de fonctionner
 */
interface KlineProviderPort
{
    public function getKlines(string $symbol, Timeframe $timeframe, int $limit = 100): array;
}
