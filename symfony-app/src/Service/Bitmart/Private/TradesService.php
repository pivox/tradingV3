<?php

declare(strict_types=1);

namespace App\Service\Bitmart\Private;

use App\Bitmart\Http\BitmartHttpClientPrivate;

final class TradesService
{
    public function __construct(
        private readonly BitmartHttpClientPrivate $client,
    ) {}

    /**
     * Fills liés aux ordres.
     * @param array<string,mixed> $query
     */
    public function fills(array $query = []): array
    {
        // GET /contract/private/trades
        return $this->client->request('GET', '/contract/private/trades', $query);
    }
}


