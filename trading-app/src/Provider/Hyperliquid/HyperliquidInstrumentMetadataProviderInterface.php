<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Provider\Hyperliquid\Dto\HyperliquidInstrumentMetadataDto;

interface HyperliquidInstrumentMetadataProviderInterface
{
    public function getInstrumentMetadata(string $symbol): ?HyperliquidInstrumentMetadataDto;
}
