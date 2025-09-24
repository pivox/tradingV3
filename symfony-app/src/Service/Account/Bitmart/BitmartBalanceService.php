<?php

declare(strict_types=1);

namespace App\Service\Account\Bitmart;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BitmartBalanceService
{
    private string $spotBase = 'https://api-cloud.bitmart.com';
    private string $futuresBase = 'https://api-cloud-v2.bitmart.com';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $bitmartApiKey, // injecté via paramètres/env
    ) {}

    /** GET /account/v1/wallet?currency=USDT&needUsdValuation=true */
    public function getAccountBalance(?string $currency = null, bool $needUsdValuation = true): array
    {
        $query = array_filter([
            'currency' => $currency,
            'needUsdValuation' => $needUsdValuation ? 'true' : 'false',
        ], static fn($v) => $v !== null);

        $resp = $this->http->request('GET', $this->spotBase.'/account/v1/wallet', [
            'headers' => ['X-BM-KEY' => $this->bitmartApiKey],
            'query'   => $query,
            'timeout' => 10,
        ]);

        return $this->decode($resp->getContent());
    }

    /** GET /spot/v1/wallet (toutes les devises du wallet Spot) */
    public function getSpotWallet(): array
    {
        $resp = $this->http->request('GET', $this->spotBase.'/spot/v1/wallet', [
            'headers' => ['X-BM-KEY' => $this->bitmartApiKey],
            'timeout' => 10,
        ]);

        return $this->decode($resp->getContent());
    }

    /** GET /contract/private/assets-detail (Futures V2) */
    public function getFuturesAssets(): array
    {
        $resp = $this->http->request('GET', $this->futuresBase.'/contract/private/assets-detail', [
            'headers' => ['X-BM-KEY' => $this->bitmartApiKey],
            'timeout' => 10,
        ]);

        return $this->decode($resp->getContent());
    }

    private function decode(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['code'])) {
            throw new \RuntimeException('Unexpected BitMart response');
        }
        if ((int)$data['code'] !== 1000) {
            $message = $data['message'] ?? 'BitMart error';
            throw new \RuntimeException("BitMart API error: {$message}");
        }
        return $data['data'] ?? [];
    }
}
