<?php

declare(strict_types=1);

namespace App\Provider;

use Psr\Log\LoggerInterface;
use Redis;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service pour publier les ordres dans Redis pour le watcher bitmart-ws-watcher.
 * 
 * Format du message publié:
 * {
 *     "symbol": "BTC_USDT",
 *     "side": "BUY",
 *     "size": 100,
 *     "price": 50000.0,
 *     "sl_price": 49000.0,
 *     "tp_price": 51000.0,
 *     "order_id": "1234567890",
 *     "client_order_id": "client_abc123",
 *     "order_group_id": "group_123",
 *     "strategy_id": "strategy_456",
 *     "timestamp": "2025-01-15T10:30:00Z"
 * }
 */
final class OrderWatcherPublisher
{
    private const DEFAULT_CHANNEL = 'watcher:order:watch';

    public function __construct(
        #[Autowire(service: 'App\Provider\Redis\RedisPubSubClient')]
        private readonly ?Redis $redis = null,
        #[Autowire(service: 'monolog.logger.bitmart')]
        private readonly ?LoggerInterface $logger = null,
        #[Autowire('%env(string:REDIS_ORDER_WATCH_CHANNEL)%')]
        private readonly string $channel = self::DEFAULT_CHANNEL,
    ) {
    }

    /**
     * Publie un ordre dans Redis pour le watcher.
     * 
     * @param array<string, mixed> $orderData Données de l'ordre
     * @return bool True si publié avec succès, False sinon
     */
    public function publishOrder(array $orderData): bool
    {
        if (!$this->redis) {
            $this->logger?->warning('[OrderWatcherPublisher] Redis non disponible, message non publié');
            return false;
        }

        try {
            // Valider les champs requis
            $requiredFields = ['symbol', 'side', 'size', 'client_order_id'];
            foreach ($requiredFields as $field) {
                if (!isset($orderData[$field])) {
                    $this->logger?->warning(
                        '[OrderWatcherPublisher] Champ requis manquant: {field}',
                        ['field' => $field, 'order_data' => $orderData]
                    );
                    return false;
                }
            }

            // Construire le message
            $message = [
                'symbol' => strtoupper((string) $orderData['symbol']),
                'side' => strtoupper((string) $orderData['side']),
                'size' => (int) $orderData['size'],
                'client_order_id' => (string) $orderData['client_order_id'],
                'order_id' => isset($orderData['order_id']) ? (string) $orderData['order_id'] : null,
                'price' => isset($orderData['price']) ? (float) $orderData['price'] : null,
                'sl_price' => isset($orderData['sl_price']) ? (float) $orderData['sl_price'] : null,
                'tp_price' => isset($orderData['tp_price']) ? (float) $orderData['tp_price'] : null,
                'order_group_id' => isset($orderData['order_group_id']) ? (string) $orderData['order_group_id'] : null,
                'strategy_id' => isset($orderData['strategy_id']) ? (string) $orderData['strategy_id'] : null,
                'status' => isset($orderData['status']) ? (string) $orderData['status'] : 'new',
                'filled_size' => isset($orderData['filled_size']) ? (int) $orderData['filled_size'] : 0,
                'filled_notional' => isset($orderData['filled_notional']) ? (float) $orderData['filled_notional'] : null,
                'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            ];

            // Nettoyer les valeurs null (optionnel)
            $message = array_filter($message, fn($value) => $value !== null);

            // Publier dans Redis
            $jsonMessage = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $result = $this->redis->publish($this->channel, $jsonMessage);

            $this->logger?->info(
                '[OrderWatcherPublisher] Ordre publié dans Redis',
                [
                    'channel' => $this->channel,
                    'client_order_id' => $message['client_order_id'],
                    'symbol' => $message['symbol'],
                    'subscribers' => $result,
                ]
            );

            return $result > 0;
        } catch (\Throwable $e) {
            $this->logger?->error(
                '[OrderWatcherPublisher] Erreur lors de la publication dans Redis',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );
            return false;
        }
    }
}

