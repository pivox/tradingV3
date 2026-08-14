<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Http;

interface HyperliquidPaperInstrumentMetadataClientInterface
{
    /** @return list<array{coin: string, asset_id: int, sz_decimals: int, max_leverage: int}> */
    public function instrumentMetadata(): array;
}
