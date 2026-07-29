<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Historical;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;

final class HyperliquidHistoricalRequestIdentity
{
    /**
     * @param list<string> $symbols
     * @param list<string> $intervals
     */
    public static function sha256(
        string $datasetId,
        PaperMarketDataNetwork $network,
        array $symbols,
        array $intervals,
        string $from,
        string $to,
        int $maximumEvents,
        int $maximumPages,
        int $maximumResponseBytes,
        int $maximumRetries,
    ): string {
        return hash('sha256', CanonicalJson::encode([
            'schema_version' => 1,
            'dataset_id' => $datasetId,
            'network' => $network->value,
            'venue' => 'hyperliquid',
            'symbols' => $symbols,
            'intervals' => $intervals,
            'from' => $from,
            'to' => $to,
            'maximum_events' => $maximumEvents,
            'maximum_pages' => $maximumPages,
            'maximum_response_bytes' => $maximumResponseBytes,
            'maximum_retries' => $maximumRetries,
        ]));
    }
}
