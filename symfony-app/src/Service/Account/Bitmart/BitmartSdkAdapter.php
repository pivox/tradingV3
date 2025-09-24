<?php
// src/Service/Account/Bitmart/BitmartSdkAdapter.php
declare(strict_types=1);

namespace App\Service\Account\Bitmart;

use BitMart\Lib\CloudConfig;
use BitMart\Futures\APIContractTrading;
use BitMart\Futures\APIContractMarket;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class BitmartSdkAdapter
{
    private const BASE = 'https://api-cloud-v2.bitmart.com';

    private APIContractTrading $trading;
    private APIContractMarket  $market;

    public function __construct(
        string $apiKey,
        string $secretKey,
        string $memo,
        private readonly HttpClientInterface $http,
        int $timeoutSecond = 10
    ) {
        $config = new CloudConfig([
            'accessKey'     => $apiKey,
            'secretKey'     => $secretKey,
            'memo'          => $memo,
            'timeoutSecond' => $timeoutSecond,
        ]);
        $this->trading = new APIContractTrading($config);
        $this->market  = new APIContractMarket($config);

        $this->apiKey = $apiKey;
        $this->secret = $secretKey;
        $this->memo   = $memo;
    }

    /** ---- ORDERS ---- */
    public function submitOrder(array $payload): array
    {
        $symbol  = (string)($payload['symbol'] ?? '');
        $sideRaw = strtoupper((string)($payload['side'] ?? ''));
        $type    = strtolower((string)($payload['type'] ?? 'limit'));
        $size    = (int)($payload['size'] ?? $payload['quantity'] ?? 0);
        $price   = $payload['price'] ?? null;

        if ($symbol === '' || $size <= 0) {
            throw new \InvalidArgumentException('symbol et size sont requis (>0).');
        }
        if ($type === 'limit' && (!is_numeric($price) || (float)$price <= 0.0)) {
            throw new \InvalidArgumentException('price > 0 requis pour un ordre limit.');
        }

        // convention SDK: 1 = buy/long ; 2 = sell/short
        $sideInt   = \in_array($sideRaw, ['LONG','BUY'], true) ? 1 : 2;
        $clientOid = (string)($payload['client_order_id'] ?? $payload['client_oid'] ?? ('bm_'.bin2hex(random_bytes(6))));

        $req = [
            'client_order_id' => $clientOid,
            'type'            => $type,                             // limit|market
            'leverage'        => (string)($payload['leverage']  ?? '1'),
            'open_type'       => (string)($payload['open_type'] ?? 'isolated'),
            'mode'            => (int)   ($payload['mode']      ?? 1), // 1=one-way ; 2=hedge
            'size'            => $size,
        ];
        if ($type === 'limit') {
            $req['price'] = (string)$price;
        } elseif ($type === 'market' && isset($payload['notional'])) {
            $req['notional'] = (string)$payload['notional'];
        }

        $resp = $this->trading->submitOrderV2($symbol, $sideInt, $req);
        dd($resp, 'submitOrder');
        return $this->unwrap($resp);
    }

    public function getOrder(string $symbol, string $orderId): array
    {
        // SDK direct si dispo
        if (method_exists($this->trading, 'getOrder')) {
            $resp = $this->trading->getOrder($symbol, $orderId);
            dd($resp, 'getOrder');
            return $this->unwrap($resp);
        }
        // Fallback HTTP (KEYED)
        return $this->requestKeyed('GET', '/contract/private/order', [
            'symbol' => $symbol,
            'order_id' => $orderId,
        ]);
    }

    /** ---- ACCOUNT / POSITIONS (KEYED) ---- */
    public function getAssetsDetail(): array
    {
        // Plusieurs versions du SDK ne l’exposent pas => fallback HTTP (KEYED)
        // GET /contract/private/assets-detail
        return $this->requestKeyed('GET', '/contract/private/assets-detail');
    }

    public function getPositionV2(?string $symbol = null, ?string $account = null): array
    {
        // SDK récent : getPositionV2(array $params) ; sinon fallback HTTP (KEYED)
        if (method_exists($this->trading, 'getPositionV2')) {
            $params = array_filter(['symbol' => $symbol, 'account' => $account]);
            $resp = $this->trading->getPositionV2($params);
            dd($resp, 'getPositionV2');
            return $this->unwrap($resp);
        }
        return $this->requestKeyed('GET', '/contract/private/position-v2', array_filter([
            'symbol' => $symbol,
            'account' => $account,
        ]));
    }

    /** ---- MARKET PUBLIC ---- */
    public function getContractDetails(string $symbol): array
    {
        return $this->requestPublic('GET', '/contract/public/details', ['symbol' => $symbol]);
    }

    /* ======================= */
    /* ==== HTTP FALLBACKS ====*/
    /* ======================= */

    private string $apiKey;
    private string $secret;
    private string $memo;

    /** KEYED: X-BM-KEY, pas de signature requise selon la doc Futures V2 */
    private function requestKeyed(string $method, string $path, array $query = []): array
    {
        $resp = $this->http->request($method, self::BASE.$path, [
            'headers' => [
                'X-BM-KEY' => $this->apiKey,
            ],
            'query' => $query,
            'timeout' => 10,
        ]);
        return $this->decode($resp->getContent());
    }

    /** PUBLIC: sans auth */
    private function requestPublic(string $method, string $path, array $query = []): array
    {
        $resp = $this->http->request($method, self::BASE.$path, [
            'query' => $query,
            'timeout' => 10,
        ]);
        return $this->decode($resp->getContent());
    }

    /** Décodage & validation code=1000 */
    private function decode(string $json): array
    {
        $data = \json_decode($json, true);
        if (!\is_array($data) || !isset($data['code'])) {
            throw new \RuntimeException('BitMart: réponse inattendue');
        }
        if ((int)$data['code'] !== 1000) {
            $message = $data['message'] ?? 'BitMart error';
            $trace   = $data['trace']   ?? '';
            $extra   = $trace ? " (trace: {$trace})" : '';
            throw new \RuntimeException("BitMart API error: {$message}{$extra}");
        }
        return $data['data'] ?? [];
    }

    private function unwrap(array $resp): array
    {
        if (isset($resp['response'])) {
            $r = (array) $resp['response'];
            $code = (int)($r['code'] ?? 0);
            if ($code !== 1000) {
                // supporte 'msg' (ex: 30030) ou 'message'
                $msg = $r['msg'] ?? $r['message'] ?? 'BitMart error';
                $trace = $r['trace'] ?? '';
                throw new \RuntimeException("BitMart API error ({$code}): {$msg}" . ($trace ? " [trace: {$trace}]" : ''));
            }
            return $r['data'] ?? $r;
        }
        if (isset($resp['code']) && (int)$resp['code'] !== 1000) {
            $msg = $resp['msg'] ?? $resp['message'] ?? 'BitMart error';
            $trace = $resp['trace'] ?? '';
            throw new \RuntimeException("BitMart API error ({$resp['code']}): {$msg}" . ($trace ? " [trace: {$trace}]" : ''));
        }
        return $resp['data'] ?? $resp;
    }

    public function submitOrderV2(
        string $symbol,
        int    $side,                 // 1..4 (one-way)
        int    $size,                 // entier (contrats)
        string $type = 'limit',       // 'limit' | 'market'
        ?string $price = null,        // string si limit
        string $leverage = '1',       // string
        ?string $openType = null,     // 'isolated' | 'cross' (optionnel)
        int    $mode = 1,             // 1=GTC, 4=Maker Only (post-only)
        ?string $clientOid = null
    ): array {
        if (!in_array($type, ['limit','market'], true)) {
            throw new \InvalidArgumentException("type doit être 'limit' ou 'market'");
        }
        if ($size <= 0) {
            throw new \InvalidArgumentException('size doit être un entier > 0');
        }
        if ($type === 'limit') {
            if ($price === null || !is_numeric($price)) {
                throw new \InvalidArgumentException('price (string) requis pour un ordre limit');
            }
        }

        $payload = [
            'symbol'          => $symbol,
            'client_order_id' => $clientOid ?? ('BM'.bin2hex(random_bytes(6))),
            'side'            => $side,
            'mode'            => $mode,
            'type'            => $type,
            'leverage'        => $leverage,
            'size'            => $size,
        ];
        if ($type === 'limit') {
            $payload['price'] = $price; // ex: "60000"
        }
        // Optionnel: si tu veux réellement pousser le mode de marge via cet appel.
        if ($openType !== null) {       // valeurs acceptées: 'isolated' | 'cross'
            $payload['open_type'] = $openType;
        }

        return $this->requestSignedV2('POST', '/contract/private/submit-order', [], $payload);
    }



    private function requestSignedV2(string $method, string $path, array $query = [], array $body = []): array
    {
        $ts = (string) \round(microtime(true) * 1000);

        // payload to sign
        if (\in_array($method, ['GET','DELETE'], true)) {
            \ksort($query);
            $payload  = \http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $bodyJson = null;
        } else {
            $bodyJson = $body ? \json_encode($body, JSON_UNESCAPED_SLASHES) : '{}';
            if ($bodyJson === false) throw new \RuntimeException('JSON encode failed');
            $payload = $bodyJson;
        }

        $sign = \hash_hmac('sha256', $ts . '#' . $this->memo . '#' . $payload, $this->secret);

        $headers = [
            'X-BM-KEY'       => $this->apiKey,
            'X-BM-TIMESTAMP' => $ts,
            'X-BM-SIGN'      => $sign,
        ];
        if ($bodyJson !== null) $headers['Content-Type'] = 'application/json';

        $resp = $this->http->request($method, self::BASE . $path, [
            'headers' => $headers,
            'query'   => $query,
            'body'    => $bodyJson,
            'timeout' => 10,
        ]);

        $raw = $resp->getContent(false);
        $data = \json_decode($raw, true);
        if (!\is_array($data) || !isset($data['code'])) {
            throw new \RuntimeException("BitMart unexpected response: {$raw}");
        }
        if ((int)$data['code'] !== 1000) {
            $msg   = $data['msg'] ?? $data['message'] ?? 'BitMart error';
            $trace = $data['trace'] ?? '';
            throw new \RuntimeException("BitMart API error ({$data['code']}): {$msg}" . ($trace ? " [trace: {$trace}]" : ''));
        }
        return $data['data'] ?? [];
    }



}
