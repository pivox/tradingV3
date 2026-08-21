<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Http;

interface OkxPaperFundingRateClientInterface
{
    /** @return array<string, string> */
    public function fundingRate(string $instrumentId): array;
}
