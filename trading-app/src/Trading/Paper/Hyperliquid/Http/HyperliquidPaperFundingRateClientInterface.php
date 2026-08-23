<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Http;

interface HyperliquidPaperFundingRateClientInterface
{
    /** @return list<array{coin: string, funding_rate: string}> */
    public function fundingRates(): array;
}
