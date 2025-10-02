<?php

declare(strict_types=1);

namespace App\Service\Bitmart\Private;

use App\Bitmart\Http\BitmartHttpClientPrivate;

final class OrdersService
{
    public function __construct(
        private readonly BitmartHttpClientPrivate $client,
    ) {}

    /**
     * Créer un ordre. Idempotence stricte via client_order_id.
     * @param array<string,mixed> $params
     * @return array<mixed>
     */
    public function create(array $params): array
    {
        // POST /contract/private/submit-order
        return $this->client->request('POST', '/contract/private/submit-order', [], $params);
    }

    /**
     * Annuler un ordre par order_id ou client_order_id.
     * @param array<string,mixed> $params
     */
    public function cancel(array $params): array
    {
        // POST /contract/private/cancel-order
        return $this->client->request('POST', '/contract/private/cancel-order', [], $params);
    }

    /**
     * Historique des ordres
     * @param array<string,mixed> $query
     */
    public function history(array $query = []): array
    {
        // GET /contract/private/order-history-v2
        return $this->client->request('GET', '/contract/private/order-history-v2', $query);
    }

    /**
     * Liste les ordres ouverts (GET /contract/private/get-open-orders)
     * @param array<string,mixed> $query
     */
    public function open(array $query = []): array
    {
        $openOrders = $this->client->request('GET', '/contract/private/get-open-orders', $query);

        $currentPlan = [];
        try {
            $currentPlan = $this->client->request('GET', '/contract/private/current-plan-order', $query);
        } catch (\Throwable $e) {
            // certains tenants peuvent ne pas supporter l'endpoint plan; on ignore
        }

        return [
            'orders' => $openOrders['data']['orders'] ?? $openOrders['data'] ?? [],
            'plan_orders' => $currentPlan['data']['orders'] ?? $currentPlan['data'] ?? [],
        ];
    }
}
