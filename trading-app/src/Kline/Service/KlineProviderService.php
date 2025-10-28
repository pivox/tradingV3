<?php

declare(strict_types=1);

namespace App\Kline\Service;

use App\Common\Enum\Timeframe;
use App\Kline\Port\KlineProviderPort;
use Psr\Log\LoggerInterface;

/**
 * Service de provider de klines
 * Implémentation temporaire pour permettre au système de fonctionner
 */
class KlineProviderService implements KlineProviderPort
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function getKlines(string $symbol, Timeframe $timeframe, int $limit = 100): array
    {
        $this->logger->info('[KlineProviderService] Getting klines', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'limit' => $limit,
        ]);

        return [];
    }
}
