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
    private const MAX_RETRIES = 3;           // Retries on 429
    private const BASE_BACKOFF_MS = 300;     // Initial backoff
    private const MAX_BACKOFF_MS = 2000;     // Cap per attempt

    // Chemins d'API BitMart privés
    private const PATH_ACCOUNT = '/contract/private/assets-detail';
    private const PATH_POSITION = '/contract/private/position-v2';
    private const PATH_ORDER_PENDING = '/contract/private/get-open-orders';
    private const PATH_PLAN_ORDERS = '/contract/private/current-plan-order'; // Pour les ordres TP/SL (Plan Orders)
    private const PATH_ORDER_DETAIL = '/contract/private/order-detail';
    private const PATH_ORDER_HISTORY = '/contract/private/order-history';
    private const PATH_TRADES = '/contract/private/trades';
    private const PATH_TRANSACTION_HISTORY = '/contract/private/transaction-history';
    private const PATH_SUBMIT_ORDER = '/contract/private/submit-order';
    private const PATH_CANCEL_ORDER = '/contract/private/cancel-order';
    private const PATH_CANCEL_ALL_ORDERS = '/contract/private/cancel-all-orders';
    private const PATH_CANCEL_ALL_AFTER = '/contract/private/cancel-all-after';
    private const PATH_FEE_RATE = '/contract/private/fee-rate';
    private const PATH_SUBMIT_LEVERAGE = '/contract/private/submit-leverage';
    // Default auto-cancel after order submission (seconds)
    // Note: BitMart caps cancel-all-after to ~60s; higher values will be clamped.
    private const DEFAULT_CANCEL_AFTER_SECONDS = 120;

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
     * @param float|null $timeout
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null, ?float $timeout = null): array
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
            'timeout' => $timeout ?? self::TIMEOUT,
        ];

        if ($json !== null) {
            $options['json'] = $json;
        }

        $attempt = 0;
        do {
            try {
                $response = $this->bitmartFuturesV2->request($method, $path, $options);
                // Mettre à jour les infos de rate à partir des headers
                $this->updateBucketFromHeaders($bucketKey, $response->getHeaders(false));
                $status = $response->getStatusCode();
                $rawContent = $response->getContent(false);

                // 429 Too Many Requests → backoff + retry
                if ($status === 429) {
                    $attempt++;
                    $waitUs = $this->computeBackoffUs($bucketKey, $response->getHeaders(false), $attempt);
                    $this->bitmartLogger->warning('[Bitmart] 429 Too Many Requests; backing off', [
                        'method' => $method,
                        'path' => $path,
                        'attempt' => $attempt,
                        'wait_ms' => (int) round($waitUs / 1000),
                        'raw' => substr($rawContent, 0, 300),
                    ]);
                    if ($attempt >= self::MAX_RETRIES) {
                        // Dernière tentative → lever avec le message brut
                        throw new \RuntimeException('BitMart HTTP 429 after retries: ' . $rawContent);
                    }
                    if ($waitUs > 0) {
                        usleep($waitUs);
                    }
                    // Essayer de nouveau
                    continue;
                }

                // Log détaillé pour debug
                $this->bitmartLogger->debug('[Bitmart] Request details', [
                    'method' => $method,
                    'path' => $path,
                    'query' => $query,
                    'full_url' => ($method . ' ' . $path . (empty($query) ? '' : '?' . http_build_query($query))),
                ]);

                $parsed = $this->parseBitmartResponse($response, $path);
                $this->bitmartLogger->info('[Bitmart] Response', [
                    'method' => $method,
                    'path' => $path,
                    'status' => $status,
                    'code' => $parsed['code'] ?? null,
                    'message' => $parsed['message'] ?? null,
                    'has_data' => isset($parsed['data']),
                    'data_keys' => isset($parsed['data']) && is_array($parsed['data']) ? array_keys($parsed['data']) : null,
                ]);

                // Log détaillé pour les endpoints de listing
                if (in_array($path, [self::PATH_ORDER_PENDING, self::PATH_POSITION, self::PATH_PLAN_ORDERS], true)) {
                    $this->bitmartLogger->debug('[Bitmart] Listing endpoint response', [
                        'path' => $path,
                        'status' => $status,
                        'code' => $parsed['code'] ?? null,
                        'raw_response_preview' => substr($rawContent, 0, 500),
                        'parsed_data_structure' => isset($parsed['data']) ? json_encode($parsed['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null,
                    ]);
                }

                return $parsed;
            } catch (\Throwable $e) {
                // Include JSON payload for submit-order failures to aid diagnostics
                $context = [
                    'method' => $method,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ];
                if ($path === self::PATH_SUBMIT_ORDER && $json !== null) {
                    $context['json'] = $json;
                }
                $this->bitmartLogger->error('[Bitmart] Request failed', $context);
                throw $e;
            }
        } while ($attempt < self::MAX_RETRIES);
        // Inatteignable mais requis par l'analyseur
        throw new \RuntimeException('BitMart unexpected error (retry loop exited)');
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
            case self::PATH_PLAN_ORDERS:
                $bucket = 'PRIVATE_GET_PLAN_ORDERS';
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
            case self::PATH_TRADES:
                $bucket = 'PRIVATE_TRADES';
                $limit = 6; $window = 2.0;
                break;
            case self::PATH_TRANSACTION_HISTORY:
                $bucket = 'PRIVATE_TRANSACTION_HISTORY';
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
            case self::PATH_CANCEL_ALL_AFTER:
                $bucket = 'PRIVATE_CANCEL_ALL_AFTER';
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
     * @param float|null $timeout
     * @return array<mixed>
     */
    private function requestJsonPrivate(string $method, string $path, array $query = [], ?array $json = null, ?float $timeout = null): array
    {
        return $this->request($method, $path, $query, $json, $timeout);
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
    private function parseBitmartResponse(ResponseInterface $response, string $path = ''): array
    {
        $status = $response->getStatusCode();

        // Gérer les HTTP 404 pour les endpoints de listing (peut arriver quand pas de résultats)
        if ($status === 404 && in_array($path, [self::PATH_ORDER_PENDING, self::PATH_POSITION, self::PATH_PLAN_ORDERS], true)) {
            $content = $response->getContent(false);
            try {
                $json = json_decode($content, true);
                // Si c'est un 404 avec code 30000, traiter comme "pas de résultats"
                if (isset($json['code']) && (int) $json['code'] === 30000) {
                    return [
                        'code' => 30000,
                        'message' => $json['message'] ?? 'Not found',
                        'data' => [
                            'orders' => [],
                            'positions' => [],
                        ],
                    ];
                }
            } catch (\Throwable $e) {
                // Si le parsing échoue, continuer avec l'erreur normale
            }
            // Si pas de code 30000, lever l'exception normale
            throw new \RuntimeException('BitMart HTTP '.$status.': '.$content);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('BitMart HTTP '.$status.': '.$response->getContent(false));
        }

        $json = $response->toArray(false);
        $code = (int) ($json['code'] ?? 0);

        // Code 30000 "Not found" est un cas normal pour les endpoints de listing (pas de résultats)
        // Selon la doc BitMart: code 30000 avec HTTP 200 = "Not found" (état normal)
        if ($code === 30000 && in_array($path, [self::PATH_ORDER_PENDING, self::PATH_POSITION, self::PATH_PLAN_ORDERS], true)) {
            // Retourner une structure vide pour indiquer "pas de résultats" sans lever d'exception
            return [
                'code' => 30000,
                'message' => $json['message'] ?? 'Not found',
                'data' => [
                    'orders' => [],
                    'positions' => [],
                ],
            ];
        }

        if ($code !== 1000) {
            $code = $json['code'] ?? 'unknown';
            $msg  = $json['message'] ?? 'unknown';
            throw new \RuntimeException('BitMart API error: code='.$code.' message='.$msg);
        }
        return $json;
    }

    /**
     * Calcule le backoff (microsecondes) à partir des headers ou fallback exponentiel (avec jitter).
     */
    private function computeBackoffUs(string $bucketKey, array $headers, int $attempt): int
    {
        $now = microtime(true);

        // 1) Retry-After (secondes) si présent
        $retryAfter = $this->findHeaderValue($headers, 'Retry-After');
        if (is_string($retryAfter) && is_numeric(trim($retryAfter))) {
            $sec = (float) trim($retryAfter);
            return (int) round(min($sec * 1000.0, self::MAX_BACKOFF_MS) * 1000.0);
        }

        // 2) Headers X-BM-RateLimit-Reset précédemment enregistrés (reset_ts)
        $state = $this->readBucketState($bucketKey);
        $header = $state['header'] ?? [];
        if (isset($header['reset_ts']) && is_numeric($header['reset_ts'])) {
            $resetTs = (float) $header['reset_ts'];
            if ($resetTs > $now) {
                $waitSec = max(0.0, $resetTs - $now);
                return (int) round(min($waitSec * 1000.0, self::MAX_BACKOFF_MS) * 1000.0);
            }
        }

        // 3) Fallback exponentiel avec jitter
        $base = self::BASE_BACKOFF_MS * (2 ** max(0, $attempt - 1));
        $base = min($base, self::MAX_BACKOFF_MS);
        $jitter = random_int(0, (int) round($base * 0.25));
        return (int) (($base + $jitter) * 1000);
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
     * GET /contract/private/position-v2
     * Récupère les positions ouvertes.
     *
     * @param string|null $symbol Symbole à filtrer (optionnel)
     * @param string|null $account Type de compte: 'futures' ou 'copy_trading' (optionnel, par défaut 'futures')
     */
    public function getPositions(?string $symbol = null, ?string $account = 'futures'): array
    {
        $query = [];
        if ($symbol !== null) {
            $query['symbol'] = $symbol;
        }
        // Ajouter le paramètre account si spécifié (selon la doc BitMart, certains endpoints le requièrent)
        if ($account !== null) {
            $query['account'] = $account;
        }
        return $this->requestJsonPrivate('GET', self::PATH_POSITION, $query);
    }

    /**
     * GET /contract/private/get-open-orders
     * Récupère les ordres en attente (ordres normaux, pas les TP/SL).
     *
     * @param string|null $symbol Symbole à filtrer (optionnel)
     * @param string|null $account Type de compte: 'futures' ou 'copy_trading' (optionnel, par défaut 'futures')
     */
    public function getOpenOrders(?string $symbol = null, ?string $account = 'futures'): array
    {
        $query = [];
        if ($symbol !== null) {
            $query['symbol'] = $symbol;
        }
        // Ajouter le paramètre account si spécifié (selon la doc BitMart, certains endpoints le requièrent)
        if ($account !== null) {
            $query['account'] = $account;
        }
        return $this->requestJsonPrivate('GET', self::PATH_ORDER_PENDING, $query);
    }

    /**
     * GET /contract/private/current-plan-order
     * Récupère les ordres planifiés (Plan Orders) incluant les TP/SL.
     *
     * @param string|null $symbol Symbole à filtrer (optionnel)
     * @param string|null $account Type de compte: 'futures' ou 'copy_trading' (optionnel, par défaut 'futures')
     */
    public function getPlanOrders(?string $symbol = null, ?string $account = 'futures'): array
    {
        $query = [];
        if ($symbol !== null) {
            $query['symbol'] = $symbol;
        }
        // Ajouter le paramètre account si spécifié
        if ($account !== null) {
            $query['account'] = $account;
        }
        return $this->requestJsonPrivate('GET', self::PATH_PLAN_ORDERS, $query);
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
     * 
     * @param string $symbol Symbole (obligatoire), ex: GLMUSDT, MELANIAUSDT
     * @param int $limit Limite de résultats (max 200)
     * @param int|null $startTime Timestamp de début en secondes (optionnel, par défaut: 7 derniers jours)
     * @param int|null $endTime Timestamp de fin en secondes (optionnel, par défaut: maintenant)
     * @param string|null $account Type de compte: 'futures' ou 'copy_trading' (optionnel)
     * @return array Réponse de l'API BitMart
     */
    public function getOrderHistory(
        string $symbol, 
        int $limit = 100, 
        ?int $startTime = null, 
        ?int $endTime = null,
        ?string $account = null
    ): array {
        $query = [
            'symbol' => $symbol,
            'limit' => min($limit, 200), // BitMart limite à 200 ordres max
        ];
        
        if ($startTime !== null) {
            $query['start_time'] = $startTime;
        }
        
        if ($endTime !== null) {
            $query['end_time'] = $endTime;
        }
        
        if ($account !== null) {
            $query['account'] = $account;
        }
        
        return $this->requestJsonPrivate('GET', self::PATH_ORDER_HISTORY, $query);
    }

    /**
     * GET /contract/private/trades
     * Récupère les trades (fills) pour un symbole.
     * 
     * @param string|null $symbol Symbole (optionnel, si null retourne tous les symboles)
     * @param int $limit Limite de résultats (max 200)
     * @param int|null $startTime Timestamp de début en secondes (optionnel)
     * @param int|null $endTime Timestamp de fin en secondes (optionnel)
     * @param string|null $account Type de compte: 'futures' ou 'copy_trading' (optionnel)
     * @return array Réponse de l'API BitMart
     */
    public function getTrades(
        ?string $symbol = null,
        int $limit = 100,
        ?int $startTime = null,
        ?int $endTime = null,
        ?string $account = null
    ): array {
        $query = [
            'limit' => min($limit, 200), // BitMart limite à 200 trades max
        ];
        
        if ($symbol !== null) {
            $query['symbol'] = $symbol;
        }
        
        if ($startTime !== null) {
            $query['start_time'] = $startTime;
        }
        
        if ($endTime !== null) {
            $query['end_time'] = $endTime;
        }
        
        if ($account !== null) {
            $query['account'] = $account;
        }
        
        return $this->requestJsonPrivate('GET', self::PATH_TRADES, $query);
    }

    /**
     * GET /contract/private/transaction-history
     * Récupère l'historique des transactions (PnL réalisé, funding, etc.).
     * 
     * @param string|null $symbol Symbole (optionnel)
     * @param int|null $flowType Type de flux: 1=transfer, 2=realized PnL, 3=funding, etc. (optionnel)
     * @param int $limit Limite de résultats (max 200)
     * @param int|null $startTime Timestamp de début en secondes (optionnel)
     * @param int|null $endTime Timestamp de fin en secondes (optionnel)
     * @param string|null $account Type de compte: 'futures' ou 'copy_trading' (optionnel)
     * @return array Réponse de l'API BitMart
     */
    public function getTransactionHistory(
        ?string $symbol = null,
        ?int $flowType = null,
        int $limit = 100,
        ?int $startTime = null,
        ?int $endTime = null,
        ?string $account = null
    ): array {
        $query = [
            'limit' => min($limit, 200), // BitMart limite à 200 transactions max
        ];
        
        if ($symbol !== null) {
            $query['symbol'] = $symbol;
        }
        
        if ($flowType !== null) {
            $query['flow_type'] = $flowType;
        }
        
        if ($startTime !== null) {
            $query['start_time'] = $startTime;
        }
        
        if ($endTime !== null) {
            $query['end_time'] = $endTime;
        }
        
        if ($account !== null) {
            $query['account'] = $account;
        }
        
        return $this->requestJsonPrivate('GET', self::PATH_TRANSACTION_HISTORY, $query);
    }

    /**
     * POST /contract/private/submit-order
     * Soumet un nouvel ordre.
     */
    public function submitOrder(array $orderData): array
    {
        // Optional per-order dead-man timeout; remove from payload before sending to Bitmart
        $cancelAfterSeconds = null;
        if (array_key_exists('cancel_after_timeout', $orderData)) {
            $cancelAfterSeconds = (int) $orderData['cancel_after_timeout'];
            unset($orderData['cancel_after_timeout']);
        }

        $result = $this->requestJsonPrivate('POST', self::PATH_SUBMIT_ORDER, [], $orderData);

        $symbol = $orderData['symbol'] ?? null;
        if ($symbol === null) {
            $this->bitmartLogger->warning('[Bitmart] submitOrder missing symbol for cancel-all-after scheduling');
            return $result;
        }

        try {
            // Only schedule cancel-all-after when explicitly requested, or when a non-zero default is configured
            $duration = $cancelAfterSeconds ?? self::DEFAULT_CANCEL_AFTER_SECONDS;
            if ($duration > 0) {
                $this->cancelAllAfter($symbol, $duration);
                $this->bitmartLogger->info('[Bitmart] Timed cancel scheduled', [
                    'symbol' => $symbol,
                    'timeout' => $duration,
                ]);
            } else {
                // No timed cancel scheduling by default
                $this->bitmartLogger->debug('[Bitmart] Timed cancel not scheduled (disabled)', [
                    'symbol' => $symbol,
                    'timeout' => $duration,
                ]);
            }
        } catch (\Throwable $e) {
            $this->bitmartLogger->warning('[Bitmart] Timed cancel scheduling failed', [
                'symbol' => $symbol,
                'timeout' => $cancelAfterSeconds ?? self::DEFAULT_CANCEL_AFTER_SECONDS,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
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
        // Cancel-all can take longer on Bitmart, allow up to 2 minutes.
        return $this->requestJsonPrivate('POST', self::PATH_CANCEL_ALL_ORDERS, [], ['symbol' => $symbol], 120.0);
    }

    /**
     * POST /contract/private/cancel-all-after
     * Programme l'annulation des ordres ouverts après un délai.
     */
    public function cancelAllAfter(string $symbol, int $timeoutSeconds): array
    {
        $duration = $timeoutSeconds;
        if ($duration < 0) {
            $duration = 0;
        } elseif ($duration > 0 && $duration < 5) {
            $duration = 5;
        }


        return $this->requestJsonPrivate('POST', self::PATH_CANCEL_ALL_AFTER, [], [
            'timeout' => $duration,
            'symbol' => $symbol,
        ]);
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
