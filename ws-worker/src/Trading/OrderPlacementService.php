<?php

declare(strict_types=1);

namespace App\Trading;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service de placement d'ordres via l'API REST Bitmart
 * 
 * Responsabilit?s:
 * - Placer des ordres limit/market via API REST Bitmart
 * - G?rer les param?tres post-only pour ?viter taker fees
 * - Valider les param?tres avant envoi
 * - G?rer les erreurs et timeouts
 */
final class OrderPlacementService
{
    private const TIMEOUT = 5.0;
    private const PATH_SUBMIT_ORDER = '/contract/private/submit-order';
    private const PATH_CANCEL_ORDER = '/contract/private/cancel-order';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $apiMemo,
        private readonly string $baseUrl,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Place un ordre limit sur Bitmart
     *
     * @param string $symbol Symbole (ex: BTCUSDT)
     * @param string $side Side: 'long' ou 'short'
     * @param float $price Prix de l'ordre
     * @param float $quantity Quantit? en contracts
     * @param array<string,mixed> $options Options suppl?mentaires
     * @return array{order_id:string,success:bool,error:?string}
     */
    public function placeLimitOrder(
        string $symbol,
        string $side,
        float $price,
        float $quantity,
        array $options = []
    ): array {
        return $this->placeOrder(
            symbol: $symbol,
            side: $side,
            type: 'limit',
            quantity: $quantity,
            price: $price,
            options: $options
        );
    }

    /**
     * Place un ordre market sur Bitmart
     *
     * @param string $symbol Symbole (ex: BTCUSDT)
     * @param string $side Side: 'long' ou 'short'
     * @param float $quantity Quantit? en contracts
     * @param array<string,mixed> $options Options suppl?mentaires
     * @return array{order_id:string,success:bool,error:?string}
     */
    public function placeMarketOrder(
        string $symbol,
        string $side,
        float $quantity,
        array $options = []
    ): array {
        return $this->placeOrder(
            symbol: $symbol,
            side: $side,
            type: 'market',
            quantity: $quantity,
            price: null,
            options: $options
        );
    }

    /**
     * Annule un ordre sur Bitmart
     *
     * @param string $orderId ID de l'ordre ? annuler
     * @return array{success:bool,error:?string}
     */
    public function cancelOrder(string $orderId): array
    {
        try {
            $timestamp = (string) (int) (microtime(true) * 1000);
            $body = json_encode(['order_id' => $orderId], JSON_UNESCAPED_SLASHES);
            
            if ($body === false) {
                throw new \RuntimeException('Failed to encode JSON');
            }

            $signature = $this->sign($timestamp, $body);

            $response = $this->httpClient->request('POST', $this->baseUrl . self::PATH_CANCEL_ORDER, [
                'headers' => [
                    'X-BM-KEY' => $this->apiKey,
                    'X-BM-TIMESTAMP' => $timestamp,
                    'X-BM-SIGN' => $signature,
                    'X-BM-BROKER-ID' => 'CCN001',
                    'Content-Type' => 'application/json',
                ],
                'body' => $body,
                'timeout' => self::TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($statusCode !== 200 || ($data['code'] ?? 0) !== 1000) {
                $error = $data['message'] ?? 'Unknown error';
                $this->logger->error('[OrderPlacement] Cancel order failed', [
                    'order_id' => $orderId,
                    'status' => $statusCode,
                    'error' => $error,
                ]);
                return ['success' => false, 'error' => $error];
            }

            $this->logger->info('[OrderPlacement] Order cancelled', [
                'order_id' => $orderId,
            ]);

            return ['success' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->logger->error('[OrderPlacement] Cancel order exception', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Place un ordre sur Bitmart (m?thode g?n?rique)
     *
     * @param string $symbol Symbole (ex: BTCUSDT)
     * @param string $side Side: 'long' ou 'short'
     * @param string $type Type: 'limit' ou 'market'
     * @param float $quantity Quantit? en contracts
     * @param float|null $price Prix (requis pour limit)
     * @param array<string,mixed> $options Options suppl?mentaires
     * @return array{order_id:string,success:bool,error:?string}
     */
    private function placeOrder(
        string $symbol,
        string $side,
        string $type,
        float $quantity,
        ?float $price,
        array $options = []
    ): array {
        try {
            // Validation
            if ($type === 'limit' && $price === null) {
                throw new \InvalidArgumentException('Price is required for limit orders');
            }

            if ($quantity <= 0) {
                throw new \InvalidArgumentException('Quantity must be positive');
            }

            // Mapper side vers Bitmart format
            // 1 = open_long, 2 = close_short, 3 = close_long, 4 = open_short
            $bitmartSide = match(strtolower($side)) {
                'long', 'buy' => 1,
                'short', 'sell' => 4,
                default => throw new \InvalidArgumentException("Invalid side: {$side}")
            };

            // Construire le payload
            $payload = [
                'symbol' => $symbol,
                'side' => $bitmartSide,
                'type' => $type,
                'size' => (int) round($quantity),
            ];

            if ($price !== null) {
                $payload['price'] = (string) $price;
            }

            // Options par d?faut
            $payload['mode'] = $options['mode'] ?? 1; // 1 = GTC
            $payload['open_type'] = $options['open_type'] ?? 'isolated';

            // Post-only mode pour ?viter taker fees (seulement pour limit orders)
            if ($type === 'limit' && ($options['post_only'] ?? true)) {
                $payload['post_only'] = 1;
            }

            // Client order ID (optionnel)
            if (isset($options['client_order_id'])) {
                $payload['client_order_id'] = $options['client_order_id'];
            }

            // Leverage (optionnel)
            if (isset($options['leverage'])) {
                $payload['leverage'] = (string) $options['leverage'];
            }

            // Preset TP/SL (optionnel)
            if (isset($options['preset_take_profit_price'])) {
                $payload['preset_take_profit_price'] = (string) $options['preset_take_profit_price'];
            }
            if (isset($options['preset_stop_loss_price'])) {
                $payload['preset_stop_loss_price'] = (string) $options['preset_stop_loss_price'];
            }

            $timestamp = (string) (int) (microtime(true) * 1000);
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
            
            if ($body === false) {
                throw new \RuntimeException('Failed to encode JSON');
            }

            $signature = $this->sign($timestamp, $body);

            $this->logger->info('[OrderPlacement] Submitting order', [
                'symbol' => $symbol,
                'side' => $side,
                'type' => $type,
                'price' => $price,
                'quantity' => $quantity,
                'post_only' => $payload['post_only'] ?? 0,
            ]);

            $response = $this->httpClient->request('POST', $this->baseUrl . self::PATH_SUBMIT_ORDER, [
                'headers' => [
                    'X-BM-KEY' => $this->apiKey,
                    'X-BM-TIMESTAMP' => $timestamp,
                    'X-BM-SIGN' => $signature,
                    'X-BM-BROKER-ID' => 'CCN001',
                    'Content-Type' => 'application/json',
                ],
                'body' => $body,
                'timeout' => self::TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($statusCode !== 200 || ($data['code'] ?? 0) !== 1000) {
                $error = $data['message'] ?? 'Unknown error';
                $this->logger->error('[OrderPlacement] Submit order failed', [
                    'symbol' => $symbol,
                    'status' => $statusCode,
                    'error' => $error,
                    'payload' => $payload,
                ]);
                return ['order_id' => '', 'success' => false, 'error' => $error];
            }

            $orderId = (string) ($data['data']['order_id'] ?? '');
            
            $this->logger->info('[OrderPlacement] Order placed', [
                'order_id' => $orderId,
                'symbol' => $symbol,
                'side' => $side,
                'type' => $type,
                'price' => $price,
                'quantity' => $quantity,
            ]);

            return ['order_id' => $orderId, 'success' => true, 'error' => null];
        } catch (\Throwable $e) {
            $this->logger->error('[OrderPlacement] Exception during order placement', [
                'symbol' => $symbol,
                'side' => $side,
                'error' => $e->getMessage(),
            ]);
            return ['order_id' => '', 'success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Signe une requ?te avec HMAC SHA256
     */
    private function sign(string $timestamp, string $body): string
    {
        $preHash = $timestamp . '#' . $this->apiMemo . '#' . $body;
        return hash_hmac('sha256', $preHash, $this->apiSecret);
    }
}
