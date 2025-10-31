<?php

namespace App\Provider\Bitmart\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;


final class BitmartHttpClientPrivate
{
    use throttleBitmartRequestTrait;

    private const TIMEOUT = 10.0;

    // Chemins d'API BitMart privés
    private const PATH_ACCOUNT = '/contract/private/assets-detail';
    private const PATH_POSITION = '/contract/private/position';
    private const PATH_ORDER_PENDING = '/contract/private/order-pending';
    private const PATH_ORDER_DETAIL = '/contract/private/order-detail';
    private const PATH_ORDER_HISTORY = '/contract/private/order-history';
    private const PATH_SUBMIT_ORDER = '/contract/private/submit-order';
    private const PATH_CANCEL_ORDER = '/contract/private/cancel-order';
    private const PATH_CANCEL_ALL_ORDERS = '/contract/private/cancel-all-orders';
    private const PATH_FEE_RATE = '/contract/private/fee-rate';
    private const PATH_SUBMIT_LEVERAGE = '/contract/private/submit-leverage';

    public function __construct(
        #[Autowire(service: 'http_client.bitmart_futures_v2_private')]
        private readonly HttpClientInterface $bitmartFuturesV2,

        private readonly BitmartRequestSigner $signer,
        private readonly BitmartConfig $config,

        private readonly LockFactory     $lockFactory,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire(service: 'monolog.logger.bitmart')] private readonly LoggerInterface $bitmartLogger,
    ) {
        $stateDir = $this->projectDir . '/var/bitmart';
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0775, true);
        }
        $this->throttleStatePath = $stateDir . '/throttle.timestamp';
        // Nouveau: répertoire des buckets de throttling
        $this->throttleDirPath = $stateDir . '/throttle';
        if (!is_dir($this->throttleDirPath)) {
            @mkdir($this->throttleDirPath, 0775, true);
        }
    }

    /**
     * Envoie une requête privée signée vers BitMart Futures V2.
     * $path doit commencer par '/'.
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $json
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null): array
    {
        // Throttle bucketisé par endpoint privé
        [$bucketKey, $limit, $windowSec] = $this->rateSpecForPrivate($method, $path);
        $this->bitmartLogger->info('[Bitmart] Request', [
            'method' => $method,
            'path' => $path,
            'bucket' => $bucketKey,
            'limit' => $limit,
            'window' => $windowSec,
            'query' => $query,
            'json' => $json !== null ? true : false,
        ]);
        $this->throttleBucket($this->lockFactory, $bucketKey, $limit, $windowSec);
        $timestamp = (string) (int) (microtime(true) * 1000);

        // Pour POST, utiliser le JSON directement comme payload
        $body = $json !== null ? json_encode($json, JSON_UNESCAPED_SLASHES) : '';
        $signature = $this->signer->sign($timestamp, $body);

        $options = [
            'headers' => [
                'X-BM-KEY' => $this->config->getApiKey(),
                'X-BM-TIMESTAMP' => $timestamp,
                'X-BM-SIGN' => $signature,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'query' => $query,
            'timeout' => self::TIMEOUT,
        ];

        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = $this->bitmartFuturesV2->request($method, $path, $options);
            // Mettre à jour les infos de rate à partir des headers
            $this->updateBucketFromHeaders($bucketKey, $response->getHeaders(false));
            $status = $response->getStatusCode();
            $parsed = $this->parseBitmartResponse($response);
            $this->bitmartLogger->info('[Bitmart] Response', [
                'method' => $method,
                'path' => $path,
                'status' => $status,
                'code' => $parsed['code'] ?? null,
            ]);
            return $parsed;
        } catch (\Throwable $e) {
            $this->bitmartLogger->error('[Bitmart] Request failed', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Retourne la spec de rate pour un endpoint privé donné.
     * @return array{string,int,float} [bucketKey, limit, windowSec]
     */
    private function rateSpecForPrivate(string $method, string $path): array
    {
        // Defaults Private: 12/2s (conservateur si non mappé)
        $limit = 12;
        $window = 2.0;
        $bucket = 'PRIVATE_DEFAULT';

        switch ($path) {
            case self::PATH_ACCOUNT:
                $bucket = 'PRIVATE_ASSETS_DETAIL';
                $limit = 12; $window = 2.0;
                break;
            case self::PATH_POSITION:
                $bucket = 'PRIVATE_POSITION';
                $limit = 6; $window = 2.0;
                break;
            case self::PATH_ORDER_PENDING:
                $bucket = 'PRIVATE_GET_OPEN_ORDERS';
                $limit = 50; $window = 2.0;
                break;
            case self::PATH_ORDER_DETAIL:
                $bucket = 'PRIVATE_ORDER_DETAIL';
                $limit = 50; $window = 2.0;
                break;
            case self::PATH_ORDER_HISTORY:
                $bucket = 'PRIVATE_ORDER_HISTORY';
                $limit = 6; $window = 2.0;
                break;
            case self::PATH_SUBMIT_ORDER:
                $bucket = 'PRIVATE_SUBMIT_ORDER';
                $limit = 24; $window = 2.0;
                break;
            case self::PATH_CANCEL_ORDER:
                $bucket = 'PRIVATE_CANCEL_ORDER';
                $limit = 40; $window = 2.0;
                break;
            case self::PATH_CANCEL_ALL_ORDERS:
                $bucket = 'PRIVATE_CANCEL_ALL_ORDERS';
                $limit = 2; $window = 2.0;
                break;
            case self::PATH_FEE_RATE:
                $bucket = 'PRIVATE_TRADE_FEE_RATE';
                $limit = 2; $window = 2.0;
                break;
            case self::PATH_SUBMIT_LEVERAGE:
                $bucket = 'PRIVATE_SUBMIT_LEVERAGE';
                $limit = 24; $window = 2.0;
                break;
        }

        return [$bucket, $limit, $window];
    }

    /**
     * Méthode interne: effectue la requête privée + vérifie code/message + decode JSON.
     *
     * @param array<string,string|int> $query
     * @param array<string,mixed>|null $json
     * @return array<mixed>
     */
    private function requestJsonPrivate(string $method, string $path, array $query = [], ?array $json = null): array
    {
        return $this->request($method, $path, $query, $json);
    }

    /**
     * Concat payload pour signature. Pour BitMart Futures V2 la signature privée
     * requiert timestamp#memo#payload. Ici payload = chemin + querystring + body JSON.
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $json
     */
    private function buildPayload(string $method, string $path, array $query, ?array $json): string
    {
        $qs = http_build_query($query);
        $target = $path.(strlen($qs) ? ('?'.$qs) : '');
        $body = $json !== null ? json_encode($json, JSON_UNESCAPED_SLASHES) : '';
        return strtoupper($method).'\n'.$target.'\n'.$body;
    }

    /**
     * @return array<mixed>
     * @throws TransportExceptionInterface
     */
    private function parseBitmartResponse(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('BitMart HTTP '.$status.': '.$response->getContent(false));
        }

        $json = $response->toArray(false);
        if (!isset($json['code']) || (int) $json['code'] !== 1000) {
            $code = $json['code'] ?? 'unknown';
            $msg  = $json['message'] ?? 'unknown';
            throw new \RuntimeException('BitMart API error: code='.$code.' message='.$msg);
        }
        return $json;
    }

    // ===== MÉTHODES DÉDIÉES POUR LES ENDPOINTS PRIVÉS =====

    /**
     * GET /contract/private/account
     * Récupère les informations du compte.
     */
    public function getAccount(): array
    {
        return $this->requestJsonPrivate('GET', self::PATH_ACCOUNT);
    }

    /**
     * GET /contract/private/position
     * Récupère les positions ouvertes.
     */
    public function getPositions(?string $symbol = null): array
    {
        $query = [];
        if ($symbol !== null) {
            $query['symbol'] = $symbol;
        }
        return $this->requestJsonPrivate('GET', self::PATH_POSITION, $query);
    }

    /**
     * GET /contract/private/order-pending
     * Récupère les ordres en attente.
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        $query = [];
        if ($symbol !== null) {
            $query['symbol'] = $symbol;
        }
        return $this->requestJsonPrivate('GET', self::PATH_ORDER_PENDING, $query);
    }

    /**
     * GET /contract/private/order-detail
     * Récupère les détails d'un ordre.
     */
    public function getOrderDetail(string $orderId): array
    {
        return $this->requestJsonPrivate('GET', self::PATH_ORDER_DETAIL, ['order_id' => $orderId]);
    }

    /**
     * GET /contract/private/order-history
     * Récupère l'historique des ordres.
     */
    public function getOrderHistory(string $symbol, int $limit = 100): array
    {
        return $this->requestJsonPrivate('GET', self::PATH_ORDER_HISTORY, [
            'symbol' => $symbol,
            'limit' => $limit
        ]);
    }

    /**
     * POST /contract/private/submit-order
     * Soumet un nouvel ordre.
     */
    public function submitOrder(array $orderData): array
    {
        return $this->requestJsonPrivate('POST', self::PATH_SUBMIT_ORDER, [], $orderData);
    }

    /**
     * POST /contract/private/cancel-order
     * Annule un ordre.
     */
    public function cancelOrder(string $orderId): array
    {
        return $this->requestJsonPrivate('POST', self::PATH_CANCEL_ORDER, [], ['order_id' => $orderId]);
    }

    /**
     * POST /contract/private/cancel-all-orders
     * Annule tous les ordres d'un symbole.
     */
    public function cancelAllOrders(string $symbol): array
    {
        return $this->requestJsonPrivate('POST', self::PATH_CANCEL_ALL_ORDERS, [], ['symbol' => $symbol]);
    }

    /**
     * GET /contract/private/fee-rate
     * Récupère les frais de trading.
     */
    public function getFeeRate(string $symbol): array
    {
        return $this->requestJsonPrivate('GET', self::PATH_FEE_RATE, ['symbol' => $symbol]);
    }

    /**
     * POST /contract/private/submit-leverage
     * Définit le levier pour un symbole.
     */
    public function submitLeverage(string $symbol, int $leverage, string $openType = 'isolated'): array
    {
        return $this->requestJsonPrivate('POST', self::PATH_SUBMIT_LEVERAGE, [], [
            'symbol' => $symbol,
            'leverage' => (string) $leverage,
            'open_type' => $openType
        ]);
    }
}
