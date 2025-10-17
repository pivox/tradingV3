<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Ports\Out\TradingProviderPort;
use App\Infrastructure\Config\BitmartConfig;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;
use function usleep;

final class BitmartClient implements TradingProviderPort
{
    private const THROTTLE_MICROSECONDS = 200_000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly BitmartConfig $config,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Récupère les klines via l'API publique
     */
    public function getKlines(
        string $symbol,
        int $step,
        ?int $startTime = null,
        ?int $endTime = null,
        int $limit = 500
    ): array {
        $url = $this->config->getKlinesUrl();
        
        $params = [
            'symbol' => $symbol,
            'step' => $step,
            'limit' => $limit
        ];
        
        if ($startTime !== null) {
            $params['start_time'] = $startTime;
        }
        
        if ($endTime !== null) {
            $params['end_time'] = $endTime;
        }
        
        $this->logger->debug('[BitMart Client] Fetching klines', [
            'symbol' => $symbol,
            'step' => $step,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'limit' => $limit
        ]);
        
        return $this->makeRequest('GET', $url, $params);
    }

    /**
     * Soumet un ordre via l'API privée
     */
    public function submitOrder(array $orderData): array
    {
        $url = $this->config->getOrderUrl();
        
        $this->logger->info('[BitMart Client] Submitting order', [
            'symbol' => $orderData['symbol'] ?? 'unknown',
            'side' => $orderData['side'] ?? 'unknown',
            'type' => $orderData['type'] ?? 'unknown'
        ]);
        
        return $this->makeAuthenticatedRequest('POST', $url, $orderData);
    }

    public function setLeverage(string $symbol, int $leverage, string $openType = 'cross'): array
    {
        $url = $this->config->getSetLeverageUrl();

        // BitMart attend généralement 'cross' | 'isolated' en clair
        $payload = [
            'symbol' => strtoupper($symbol),
            'leverage' => (string) max(1, $leverage),
            'open_type' => strtolower($openType),
        ];

        $this->logger->info('[BitMart Client] Setting leverage', $payload);

        return $this->makeAuthenticatedRequest('POST', $url, $payload);
    }

    public function submitTpSlOrder(array $payload): array
    {
        $url = $this->config->getTpSlUrl();

        $this->logger->info('[BitMart Client] Submitting TP/SL order', [
            'symbol' => $payload['symbol'] ?? 'unknown',
            'orderType' => $payload['orderType'] ?? 'unknown',
        ]);

        return $this->makeAuthenticatedRequest('POST', $url, $payload);
    }

    /**
     * Annule un ordre via l'API privée
     */
    public function cancelOrder(string $symbol, string $orderId): array
    {
        $url = $this->config->getCancelOrderUrl();
        
        $data = [
            'symbol' => $symbol,
            'order_id' => $orderId
        ];
        
        $this->logger->info('[BitMart Client] Canceling order', [
            'symbol' => $symbol,
            'order_id' => $orderId
        ]);
        
        return $this->makeAuthenticatedRequest('POST', $url, $data);
    }

    /**
     * Annule tous les ordres d'un symbole via l'API privée
     */
    public function cancelAllOrders(string $symbol): array
    {
        $url = $this->config->getPrivateApiUrl() . '/contract/private/cancel-orders';
        
        $data = [
            'symbol' => $symbol
        ];
        
        $this->logger->info('[BitMart Client] Canceling all orders', [
            'symbol' => $symbol
        ]);
        
        return $this->makeAuthenticatedRequest('POST', $url, $data);
    }

    public function getOpenOrders(?string $symbol = null): array
    {
        $query = [];
        if ($symbol !== null && $symbol !== '') {
            $query['symbol'] = strtoupper($symbol);
        }

        $this->logger->debug('[BitMart Client] Fetching open orders', [
            'symbol' => $query['symbol'] ?? 'ALL',
        ]);

        $ordersResponse = $this->makeAuthenticatedRequest('GET', $this->config->getOpenOrdersUrl(), $query);
        $orders = $ordersResponse['data']['orders'] ?? $ordersResponse['data'] ?? $ordersResponse['orders'] ?? [];
        if (!\is_array($orders)) {
            $orders = [];
        }

        $planOrders = [];
        try {
            $planResponse = $this->makeAuthenticatedRequest('GET', $this->config->getCurrentPlanOrdersUrl(), $query);
            $planOrders = $planResponse['data']['orders'] ?? $planResponse['data'] ?? $planResponse['plan_orders'] ?? [];
            if (!\is_array($planOrders)) {
                $planOrders = [];
            }
        } catch (Throwable $exception) {
            $this->logger->warning('[BitMart Client] Failed to fetch plan orders', [
                'symbol' => $query['symbol'] ?? 'ALL',
                'error' => $exception->getMessage(),
            ]);
            $planOrders = [];
        }

        return [
            'orders' => $orders,
            'plan_orders' => $planOrders,
        ];
    }

    /**
     * Récupère les positions via l'API privée
     */
    public function getPositions(?string $symbol = null): array
    {
        $url = $this->config->getPositionsUrl();
        
        $params = [];
        if ($symbol !== null) {
            $params['symbol'] = $symbol;
        }
        
        $this->logger->debug('[BitMart Client] Fetching positions', [
            'symbol' => $symbol
        ]);
        
        return $this->makeAuthenticatedRequest('GET', $url, $params);
    }

    /**
     * Récupère les détails des actifs via l'API privée
     */
    public function getAssetsDetail(): array
    {
        $url = $this->config->getAssetsUrl();
        
        $this->logger->debug('[BitMart Client] Fetching assets detail');
        
        return $this->makeAuthenticatedRequest('GET', $url);
    }

    /**
     * Effectue une requête publique
     */
    private function makeRequest(string $method, string $url, array $params = []): array
    {
        $options = [
            'timeout' => $this->config->getTimeout(),
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'MTF-Trading-System/1.0'
            ]
        ];
        
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        } elseif ($method === 'POST' && !empty($params)) {
            $options['json'] = $params;
        }
        
        return $this->executeRequest($method, $url, $options);
    }

    /**
     * Effectue une requête authentifiée
     */
    private function makeAuthenticatedRequest(string $method, string $url, array $data = []): array
    {
        // Pour l'instant, on simule l'authentification
        // Plus tard, on implémentera la signature HMAC
        $timestamp = $this->nowTimestampMs();
        $body = ($method === 'POST' && !empty($data)) ? json_encode($data, JSON_UNESCAPED_SLASHES) : '';
        $signature = $this->sign($timestamp, $body);

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'MTF-Trading-System/1.0',
            'X-BM-KEY' => $this->config->getApiKey(),
            'X-BM-TIMESTAMP' => $timestamp,
            'X-BM-SIGN' => $signature,
        ];

        $options = [
            'timeout' => $this->config->getTimeout(),
            'headers' => $headers,
        ];

        if ($method === 'POST' && !empty($data)) {
            $options['json'] = $data;
        } elseif ($method === 'GET' && !empty($data)) {
            $options['query'] = $data;
        }

        return $this->executeRequest($method, $url, $options);
    }

    /**
     * Exécute une requête avec retry
     */
    private function executeRequest(string $method, string $url, array $options): array
    {
        $lastException = null;
        
        $maxRetries = max(1, $this->config->getMaxRetries());
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            usleep(self::THROTTLE_MICROSECONDS);
            try {
                $this->logger->debug('[BitMart Client] Making request', [
                    'method' => $method,
                    'url' => $url,
                    'attempt' => $attempt + 1
                ]);
                
                $response = $this->httpClient->request($method, $url, $options);
                $statusCode = $response->getStatusCode();
                $content = $response->toArray(false);
                
                if ($statusCode >= 200 && $statusCode < 300) {
                    $this->logger->debug('[BitMart Client] Request successful', [
                        'status_code' => $statusCode,
                        'response_size' => strlen(json_encode($content))
                    ]);
                    return $content;
                }
                
                if ($statusCode === 429) {
                    // Rate limit hit
                    $this->logger->warning('[BitMart Client] Rate limit hit', [
                        'status_code' => $statusCode,
                        'attempt' => $attempt + 1
                    ]);
                    
                    if ($attempt < $maxRetries - 1) {
                        sleep(2); // Attendre 2 secondes
                        continue;
                    }
                }
                
                throw new \RuntimeException("HTTP {$statusCode}: " . json_encode($content));
                
            } catch (\Exception $e) {
                $lastException = $e;
                $this->logger->warning('[BitMart Client] Request failed', [
                    'method' => $method,
                    'url' => $url,
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempt < $maxRetries - 1) {
                    $delay = (int) pow(2, $attempt); // Backoff exponentiel
                    $this->logger->info('[BitMart Client] Retrying request', [
                        'delay_seconds' => $delay,
                        'attempt' => $attempt + 1,
                        'max_retries' => $maxRetries,
                    ]);
                    sleep($delay);
                }
            }
        }

        $this->logger->error('[BitMart Client] Max retries exceeded', [
            'method' => $method,
            'url' => $url,
            'error' => $lastException?->getMessage()
        ]);

        throw new \RuntimeException('Max retries exceeded: ' . $lastException?->getMessage(), 0, $lastException);
    }

    private function sign(string $timestamp, string $body): string
    {
        $memo = $this->config->getApiMemo();
        $secret = $this->config->getApiSecret();
        $payload = $timestamp.'#'.$memo.'#'.$body;

        return hash_hmac('sha256', $payload, $secret);
    }

    private function nowTimestampMs(): string
    {
        $now = $this->clock->now();
        $seconds = (int) $now->format('U');
        $milliseconds = (int) $now->format('v');
        return (string) (($seconds * 1000) + $milliseconds);
    }

    /**
     * Vérifie la santé de l'API
     */
    public function healthCheck(): array
    {
        try {
            $url = $this->config->getKlinesUrl();
            $params = [
                'symbol' => 'BTCUSDT',
                'step' => 1,
                'limit' => 1
            ];
            
            $response = $this->makeRequest('GET', $url, $params);
            
            return [
                'status' => 'healthy',
                'response_time' => microtime(true),
                'data' => $response
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'response_time' => microtime(true)
            ];
        }
    }

    /**
     * Obtient les informations sur les contrats
     */
    public function getContracts(): array
    {
        $url = $this->config->getContractsUrl();
        
        $this->logger->debug('[BitMart Client] Fetching contracts');
        
        return $this->makeRequest('GET', $url);
    }

    /**
     * Obtient le ticker d'un symbole
     */
    public function getTicker(string $symbol): array
    {
        $url = $this->config->getTickerUrl();
        
        $params = [
            'symbol' => $symbol
        ];
        
        $this->logger->debug('[BitMart Client] Fetching ticker', [
            'symbol' => $symbol
        ]);
        
        return $this->makeRequest('GET', $url, $params);
    }
}
