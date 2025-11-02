<?php

declare(strict_types=1);

namespace App\TradeEntry\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP pour communiquer avec ws-worker
 */
final class WsWorkerClient
{
    private const TIMEOUT = 5.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'WS_WORKER_BASE_URL')]
        private readonly string $wsWorkerBaseUrl,
        #[Autowire(service: 'monolog.logger.orders')]
        private readonly LoggerInterface $ordersLogger,
    ) {}

    /**
     * Envoie une demande de placement d'ordre ? ws-worker
     *
     * @param array<string,mixed> $orderData
     * @return array{ok:bool,message:string,order_id?:string}
     */
    public function placeOrder(array $orderData): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->wsWorkerBaseUrl . '/api/place-order', [
                'json' => $orderData,
                'timeout' => self::TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($statusCode === 200 && ($data['ok'] ?? false)) {
                $this->ordersLogger->info('[WsWorkerClient] Order placed successfully', [
                    'order_id' => $data['order_id'] ?? 'unknown',
                    'symbol' => $orderData['symbol'] ?? 'unknown',
                ]);
                return $data;
            }

            $this->ordersLogger->error('[WsWorkerClient] Failed to place order', [
                'status_code' => $statusCode,
                'error' => $data['error'] ?? 'unknown',
                'order_data' => $orderData,
            ]);

            return ['ok' => false, 'message' => $data['error'] ?? 'Unknown error'];
        } catch (\Throwable $e) {
            $this->ordersLogger->error('[WsWorkerClient] Exception during place order', [
                'error' => $e->getMessage(),
                'order_data' => $orderData,
            ]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Envoie une demande de monitoring de position ? ws-worker
     *
     * @param array<string,mixed> $positionData
     * @return array{ok:bool,message:string,position_id?:string}
     */
    public function monitorPosition(array $positionData): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->wsWorkerBaseUrl . '/api/monitor-position', [
                'json' => $positionData,
                'timeout' => self::TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($statusCode === 200 && ($data['ok'] ?? false)) {
                $this->ordersLogger->info('[WsWorkerClient] Position monitoring started', [
                    'position_id' => $data['position_id'] ?? 'unknown',
                    'symbol' => $positionData['symbol'] ?? 'unknown',
                ]);
                return $data;
            }

            $this->ordersLogger->error('[WsWorkerClient] Failed to monitor position', [
                'status_code' => $statusCode,
                'error' => $data['error'] ?? 'unknown',
                'position_data' => $positionData,
            ]);

            return ['ok' => false, 'message' => $data['error'] ?? 'Unknown error'];
        } catch (\Throwable $e) {
            $this->ordersLogger->error('[WsWorkerClient] Exception during monitor position', [
                'error' => $e->getMessage(),
                'position_data' => $positionData,
            ]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * R?cup?re le statut des ordres et positions
     *
     * @return array<string,mixed>
     */
    public function getOrdersStatus(): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->wsWorkerBaseUrl . '/api/orders/status', [
                'timeout' => self::TIMEOUT,
            ]);

            return $response->toArray(false);
        } catch (\Throwable $e) {
            $this->ordersLogger->error('[WsWorkerClient] Failed to get orders status', [
                'error' => $e->getMessage(),
            ]);
            return ['pending_orders' => 0, 'monitored_positions' => 0];
        }
    }
}
