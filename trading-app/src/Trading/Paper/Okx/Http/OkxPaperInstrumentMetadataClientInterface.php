<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Http;

interface OkxPaperInstrumentMetadataClientInterface
{
    /** @return array<string, mixed> */
    public function instrumentMetadata(string $instrumentId): array;
}
