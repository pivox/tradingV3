<?php

declare(strict_types=1);

namespace App\Service\Bitmart\Private;

use App\Bitmart\Http\BitmartHttpClientPrivate;

final class TrailOrdersService
{
    public function __construct(
        private readonly BitmartHttpClientPrivate $client,
    ) {}

    /**
     * Création d’un ordre planifié (stop/trigger/trailing)
     * @param array<string,mixed> $params
     */
    public function create(array $params): array
    {
        // POST /contract/private/submit-plan-order
        return $this->client->request('POST', '/contract/private/submit-plan-order', [], $params);
    }

    /**
     * Annulation d’un ordre planifié
     * @param array<string,mixed> $params
     */
    public function cancel(array $params): array
    {
        // POST /contract/private/cancel-plan-order
        return $this->client->request('POST', '/contract/private/cancel-plan-order', [], $params);
    }

    /**
     * Historique des ordres planifiés
     * @param array<string,mixed> $query
     */
    public function history(array $query = []): array
    {
        // GET /contract/private/plan-order-history
        return $this->client->request('GET', '/contract/private/plan-order-history', $query);
    }

    /** POST /contract/private/cancel-all-after */
    public function cancelAllAfter(string $symbol, int $timeoutSeconds): array
    {
        $payload = [
            'timeout' => $timeoutSeconds,
            'symbol'  => $symbol,
        ];
        return $this->client->request('POST', '/contract/private/cancel-all-after', [], $payload);
    }
}


