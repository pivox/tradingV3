<?php

declare(strict_types=1);

namespace App\Provider\Bitmart;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\Dto\OrderDto;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Contract\Provider\OrderProviderInterface;
use App\Provider\Bitmart\Http\BitmartHttpClientPrivate;
use App\Provider\Bitmart\Http\BitmartHttpClientPublic;
use Psr\Log\LoggerInterface;

/**
 * Provider Bitmart pour les ordres
 */
#[\Symfony\Component\DependencyInjection\Attribute\Autoconfigure(
    bind: [
        OrderProviderInterface::class => '@app.provider.bitmart.order'
    ]
)]
final class BitmartOrderProvider implements OrderProviderInterface
{
    private const RETRY_ATTEMPTS = 3;
    private const RETRY_SLEEP_US = 250_000; // 250ms

    public function __construct(
        private readonly BitmartHttpClientPrivate $bitmartClient,
        private readonly BitmartHttpClientPublic $bitmartClientPublic,
        private readonly LoggerInterface $logger
    ) {}

    public function placeOrder(
        string $symbol,
        OrderSide $side,
        OrderType $type,
        float $quantity,
        ?float $price = null,
        ?float $stopPrice = null,
        array $options = []
    ): ?OrderDto {
        try {
            // Bitmart Futures V2 expects:
            //  - side: numeric (1=open_long, 4=open_short) for entry orders
            //  - size: stringified contract size quantity
            //  - client_order_id, mode, open_type, price (for limit), preset TP/SL, ...
            // Map generic inputs to Bitmart API schema
            $bitmartSide = $options['side']
                ?? ($side === OrderSide::BUY ? 1 : 4); // default to entry orders mapping

            $payload = [
                'symbol' => $symbol,
                'side' => $bitmartSide,
                'type' => $type->value,
                // Bitmart expects size as Int (number of contracts)
                'size' => (int) round($quantity),
            ];

            if ($price !== null) {
                $payload['price'] = (string) $price;
            }

            if ($stopPrice !== null) {
                $payload['stop_price'] = (string) $stopPrice;
            }

            // Ajouter les options supplémentaires (mode, open_type, client_order_id, TP/SL, ...)
            // Note: do not let options override core-mapped keys with incompatible shapes
            foreach ($options as $k => $v) {
                if (in_array($k, ['symbol', 'type'], true)) {
                    continue;
                }
                $payload[$k] = $v;
            }

            $response = $this->bitmartClient->submitOrder($payload);

            if (isset($response['data']['order_id'])) {
                // Bitmart may return order_id as int; cast to string for DTO expectations
                $orderId = (string) $response['data']['order_id'];

                // Short retry loop for eventual consistency on order-detail
                $orderDto = null;
                for ($i = 0; $i < 3; $i++) {
                    $orderDto = $this->getOrder($orderId);
                    if ($orderDto !== null) {
                        break;
                    }
                    usleep(250_000); // 250ms
                }

                if ($orderDto !== null) {
                    return $orderDto;
                }

                // Fallback: return a minimal submitted OrderDto so caller treats it as submitted
                $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                return OrderDto::fromArray([
                    'order_id' => $orderId,
                    'symbol' => $symbol,
                    'side' => $side->value,
                    'type' => $type->value,
                    'status' => 'pending',
                    'quantity' => (string) $quantity,
                    'price' => $price !== null ? (string) $price : null,
                    'stop_price' => $stopPrice !== null ? (string) $stopPrice : null,
                    'filled_quantity' => '0',
                    'remaining_quantity' => (string) $quantity,
                    'average_price' => null,
                    'created_at' => $now,
                    'updated_at' => null,
                    'filled_at' => null,
                    'metadata' => [
                        'provider' => 'bitmart',
                        'submit_only' => true,
                    ],
                ]);
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la création de l'ordre", [
                'symbol' => $symbol,
                'side' => $side->value,
                'type' => $type->value,
                'quantity' => $quantity,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function cancelOrder(string $orderId): bool
    {
        $lastError = null;
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $response = $this->bitmartClient->cancelOrder($orderId);
                return isset($response['data']['result']) && $response['data']['result'] === 'success';
            } catch (\Throwable $e) {
                $lastError = $e;
            }

            if ($i < self::RETRY_ATTEMPTS - 1) {
                usleep(self::RETRY_SLEEP_US);
            }
        }
        if ($lastError) {
            $this->logger->error("Erreur lors de l'annulation de l'ordre", [
                'order_id' => $orderId,
                'error' => $lastError->getMessage()
            ]);
        }
        return false;
    }

    public function getOrder(string $orderId): ?OrderDto
    {
        $lastError = null;
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $response = $this->bitmartClient->getOrderDetail($orderId);
                if (isset($response['data'])) {
                    return OrderDto::fromArray($response['data']);
                }
                $lastError = new \RuntimeException('Invalid order detail response structure.');
            } catch (\Throwable $e) {
                $lastError = $e;
            }

            if ($i < self::RETRY_ATTEMPTS - 1) {
                usleep(self::RETRY_SLEEP_US);
            }
        }
        if ($lastError) {
            $this->logger->error("Erreur lors de la récupération de l'ordre", [
                'order_id' => $orderId,
                'error' => $lastError->getMessage()
            ]);
        }
        return null;
    }

    public function getOpenOrders(?string $symbol = null): array
    {
        $lastError = null;
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $response = $this->bitmartClient->getOpenOrders($symbol);
                if (isset($response['data']['orders'])) {
                    return array_map(fn($order) => OrderDto::fromArray($order), $response['data']['orders']);
                }
                $lastError = new \RuntimeException('Invalid open orders response structure.');
            } catch (\Throwable $e) {
                $lastError = $e;
            }

            if ($i < self::RETRY_ATTEMPTS - 1) {
                usleep(self::RETRY_SLEEP_US);
            }
        }
        if ($lastError) {
            $this->logger->error("Erreur lors de la récupération des ordres ouverts", [
                'symbol' => $symbol,
                'error' => $lastError->getMessage()
            ]);
        }
        return [];
    }

    public function getOrderHistory(string $symbol, int $limit = 100): array
    {
        $lastError = null;
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $response = $this->bitmartClient->getOrderHistory($symbol, $limit);
                if (isset($response['data']['orders'])) {
                    return array_map(fn($order) => OrderDto::fromArray($order), $response['data']['orders']);
                }
                $lastError = new \RuntimeException('Invalid order history response structure.');
            } catch (\Throwable $e) {
                $lastError = $e;
            }

            if ($i < self::RETRY_ATTEMPTS - 1) {
                usleep(self::RETRY_SLEEP_US);
            }
        }
        if ($lastError) {
            $this->logger->error("Erreur lors de la récupération de l'historique des ordres", [
                'symbol' => $symbol,
                'error' => $lastError->getMessage()
            ]);
        }
        return [];
    }

    public function cancelAllOrders(string $symbol): bool
    {
        try {
            $response = $this->bitmartClient->cancelAllOrders($symbol);

            return isset($response['data']['result']) && $response['data']['result'] === 'success';
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de l'annulation de tous les ordres", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function healthCheck(): bool
    {
        try {
            // Test simple pour vérifier la connectivité
            $this->bitmartClient->getAccount();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        $result = $this->bitmartClientPublic->getOrderBookTop($symbol);
        return new SymbolBidAskDto(
            symbol: $symbol,
            bid: $result['bid'],
            ask: $result['ask'],
            timestamp: (new \DateTimeImmutable())->setTimestamp($result['ts'])->setTimezone(new \DateTimeZone('UTC'))
        );
    }
    public function submitLeverage(string $symbol, int $leverage, string $openType = 'isolated'): bool
    {
        try {
            $response = $this->bitmartClient->submitLeverage($symbol, $leverage, $openType);

            return isset($response['data']['leverage']) && $response['data']['leverage'] === (string) $leverage;
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la définition du levier", [
                'symbol' => $symbol,
                'leverage' => $leverage,
                'open_type' => $openType,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getProviderName(): string
    {
        return 'Bitmart';
    }
}
