<?php

declare(strict_types=1);

namespace App\Service\Account\Bitmart;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BitmartFuturesClient
{
    private string $base = 'https://api-cloud-v2.bitmart.com';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,    // %env(BITMART_API_KEY)%
        private readonly string $apiSecret, // %env(BITMART_API_SECRET)%
        private readonly string $apiMemo,   // %env(BITMART_API_MEMO)%  (a.k.a uid/memo)
    ) {}



    // -----------------------
    // KEYED endpoints (no sign)
    // -----------------------
    public function getContractDetails(string $symbol): array
    {
        $query = ['symbol' => $symbol];
        return $this->requestPublic('GET', '/contract/public/details', $query);
    }

    /** GET /contract/private/assets-detail (KEYED) */
    public function getAssetsDetail(): array
    {
        return $this->requestKeyed('GET', '/contract/private/assets-detail');
    }

    /** GET /contract/private/position-v2 (KEYED). Si $symbol est fourni, retourne des champs à zéro s’il n’y a pas de position. */
    public function getPositionV2(?string $symbol = null, ?string $account = null): array
    {
        $query = array_filter(['symbol' => $symbol, 'account' => $account]);
        return $this->requestKeyed('GET', '/contract/private/position-v2', $query);
    }

    /** GET /contract/private/order (KEYED) */
    public function getOrder(string $symbol, ?string $orderId = null, ?string $clientOrderId = null, ?string $account = null): array
    {
        $query = array_filter([
            'symbol'          => $symbol,
            'order_id'        => $orderId,
            'client_order_id' => $clientOrderId,
            'account'         => $account, // futures | copy_trading (optionnel)
        ]);
        return $this->requestKeyed('GET', '/contract/private/order', $query);
    }

    /** GET /contract/private/get-open-orders (KEYED) */
    public function getOpenOrders(?string $symbol = null): array
    {
        $query = array_filter(['symbol' => $symbol]);
        return $this->requestKeyed('GET', '/contract/private/get-open-orders', $query);
    }

    /** GET /contract/private/trades (KEYED) — historique d’exécutions d’un ordre */
    public function getTrades(string $symbol, ?string $orderId = null, ?string $clientOrderId = null, ?string $account = null): array
    {
        $query = array_filter([
            'symbol'          => $symbol,
            'order_id'        => $orderId,
            'client_order_id' => $clientOrderId,
            'account'         => $account,
        ]);
        return $this->requestKeyed('GET', '/contract/private/trades', $query);
    }

    // -----------------------
    // SIGNED endpoints (key + sign + ts)
    // -----------------------

    /** POST /contract/private/submit-order (SIGNED) */
    public function submitOrder(array $payload): array
    {
        return $this->requestSigned('POST', '/contract/private/submit-order', [], $payload);
    }

    /** POST /contract/private/cancel-order (SIGNED) */
    public function cancelOrder(string $symbol, ?string $orderId = null, ?string $clientOrderId = null): array
    {
        $payload = array_filter([
            'symbol'          => $symbol,
            'order_id'        => $orderId,
            'client_order_id' => $clientOrderId,
        ]);
        return $this->requestSigned('POST', '/contract/private/cancel-order', [], $payload);
    }

    /** POST /contract/private/cancel-orders (SIGNED) — cancel all on symbol */
    public function cancelAllOrders(string $symbol): array
    {
        return $this->requestSigned('POST', '/contract/private/cancel-orders', [], ['symbol' => $symbol]);
    }

    /** POST /contract/private/submit-leverage (SIGNED) — régler le levier (isolated/cross) */
    public function submitLeverage(string $symbol, string $leverage, string $openType = 'isolated'): array
    {
        return $this->requestSigned('POST', '/contract/private/submit-leverage', [], [
            'symbol'    => $symbol,
            'leverage'  => $leverage,
            'open_type' => $openType, // 'cross' | 'isolated'
        ]);
    }

    public function submitOrderV2(string $symbol, int $side, int $size, string $type = 'limit', ?string $price = null, string $leverage = '1', int $mode = 1, ?string $clientOid = null): array
    {
        if (!in_array($side, [1,2,3,4], true)) {
            throw new \InvalidArgumentException('side doit être 1..4 (one_way: 1=buy,2=buy RO,3=sell RO,4=sell)');
        }
        if (!in_array($type, ['limit','market'], true)) {
            throw new \InvalidArgumentException("type doit être 'limit' ou 'market'");
        }
        if ($size <= 0) {
            throw new \InvalidArgumentException('size doit être > 0 (contrats)');
        }
        $payload = [
            'symbol'          => $symbol,
            'client_order_id' => $clientOid ?? ('BM'.bin2hex(random_bytes(6))),
            'side'            => $side,
            'mode'            => $mode,      // 1=GTC, 4=Maker Only (post-only)
            'type'            => $type,
            'leverage'        => $leverage,  // string
            'size'            => $size,      // int
        ];
        if ($type === 'limit') {
            if ($price === null || !is_numeric($price)) {
                throw new \InvalidArgumentException('price string requis pour un ordre limit');
            }
            $payload['price'] = $price; // string "60000"
        }

        // ⚠️ NE PAS ENVOYER open_type ici → source du 40045
        return $this->requestSignedV2('POST', '/contract/private/submit-order', [], $payload);
    }

    public function submitLeverageV2(string $symbol, string $leverage, string $openType): array
    {
        if (!\in_array($openType, ['cross','isolated'], true)) {
            throw new \InvalidArgumentException("openType doit être 'cross' ou 'isolated'");
        }
        $payload = [
            'symbol'    => $symbol,
            'leverage'  => $leverage,
            'open_type' => $openType,
        ];
        return $this->requestSignedV2('POST', '/contract/private/submit-leverage', [], $payload);
    }

    // =======================
    // Internals (HTTP + sign)
    // =======================

    private function requestKeyed(string $method, string $path, array $query = []): array
    {
        $resp = $this->http->request($method, $this->base.$path, [
            'headers' => [
                'X-BM-KEY' => $this->apiKey,
            ],
            'query'   => $query,
            'timeout' => 10,
        ]);

        return $this->decode($resp->getContent());
    }

    // =======================
    // Internals (HTTP + sign)
    // =======================

    private function requestPublic(string $method, string $path, array $query = []): array
    {
        $resp = $this->http->request($method, $this->base.$path, [
            'query'   => $query,
            'timeout' => 10,
        ]);
        return $this->decode($resp->getContent());
    }


    private function requestSigned(string $method, string $path, array $query = [], array $body = []): array
    {
        $ts = (string) \round(microtime(true) * 1000);
        [$payload, $options] = $this->buildPayload($method, $query, $body);

        // Signature: HMAC-SHA256(secret, TIMESTAMP + '#' + MEMO + '#' + payload)
        $sign = \hash_hmac('sha256', $ts . '#' . $this->apiMemo . '#' . $payload, $this->apiSecret);

        $headers = [
            'X-BM-KEY'       => $this->apiKey,
            'X-BM-TIMESTAMP' => $ts,
            'X-BM-SIGN'      => $sign,
        ];
        if ($method !== 'GET' && $method !== 'DELETE') {
            $headers['Content-Type'] = 'application/json';
        }

        $resp = $this->http->request($method, $this->base.$path, [
            'headers' => $headers,
            'query'   => $options['query']   ?? [],
            'body'    => $options['body']    ?? null,
            'timeout' => 10,
        ]);

        return $this->decode($resp->getContent());
    }

    /**
     * Construit la « charge utile » utilisée pour la signature :
     * - GET/DELETE : querystring triée (form format)
     * - POST/PUT   : JSON compact (sans espaces)
     */
    private function buildPayload(string $method, array $query, array $body): array
    {
        if (\in_array($method, ['GET','DELETE'], true)) {
            // IMPORTANT : trier pour une signature stable
            if (!empty($query)) {
                \ksort($query);
            }
            $qs = \http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            return [$qs, ['query' => $query]];
        }

        // POST/PUT : JSON compact
        $json = $body ? \json_encode($body, JSON_UNESCAPED_SLASHES) : '{}';
        if ($json === false) {
            throw new \RuntimeException('JSON encode failed for signed body.');
        }
        return [$json, ['body' => $json]];
    }

    /** Décodage commun BitMart (code=1000 => OK) */
    private function decode(string $json): array
    {
        $data = \json_decode($json, true);
        if (!\is_array($data) || !isset($data['code'])) {
            throw new \RuntimeException('Unexpected BitMart response');
        }
        if ((int)$data['code'] !== 1000) {
            $trace   = $data['trace']   ?? '';
            $message = $data['message'] ?? 'BitMart error';
            $extra   = $trace ? " (trace: {$trace})" : '';
            throw new \RuntimeException("BitMart API error: {$message}{$extra}");
        }
        return $data['data'] ?? [];
    }
}
