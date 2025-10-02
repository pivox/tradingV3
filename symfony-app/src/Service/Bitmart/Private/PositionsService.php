<?php

declare(strict_types=1);

namespace App\Service\Bitmart\Private;

use App\Bitmart\Http\BitmartHttpClientPrivate;

final class PositionsService
{
    public function __construct(
        private readonly BitmartHttpClientPrivate $client,
    ) {}

    /**
     * Récupère les positions (GET /contract/private/position-v2)
     * @param array<string,mixed> $query
     */
    public function list(array $query = []): array
    {
        return $this->client->request('GET', '/contract/private/position-v2', $query);
    }

    /**
     * Récupère le mode de position (one-way/hedge) (GET /contract/private/account)
     */
    public function getAccount(): array
    {
        try {
            return $this->client->request('GET', '/contract/private/account');
        } catch (\Throwable $e) {
            // fallback legacy: certains tenants exposent encore l'info via position-v2
            return $this->client->request('GET', '/contract/private/position-v2');
        }
    }

    /** POST /contract/private/submit-leverage */
    public function setLeverage(string $symbol, int $leverage, string $openType = 'isolated'): array
    {
        $payload = [
            'symbol'    => $symbol,
            'leverage'  => (string)$leverage,
            'open_type' => $openType,
        ];
        return $this->client->request('POST', '/contract/private/submit-leverage', [], $payload);
    }
}

