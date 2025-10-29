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
            $payload = [
                'symbol' => $symbol,
                'side' => $side->value,
                'type' => $type->value,
                'quantity' => (string) $quantity,
            ];

            if ($price !== null) {
                $payload['price'] = (string) $price;
            }

            if ($stopPrice !== null) {
                $payload['stop_price'] = (string) $stopPrice;
            }

            // Ajouter les options supplémentaires
            $payload = array_merge($payload, $options);

            $response = $this->bitmartClient->submitOrder($payload);

            if (isset($response['data']['order_id'])) {
                return $this->getOrder($response['data']['order_id']);
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
        try {
            $response = $this->bitmartClient->cancelOrder($orderId);

            return isset($response['data']['result']) && $response['data']['result'] === 'success';
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de l'annulation de l'ordre", [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getOrder(string $orderId): ?OrderDto
    {
        try {
            $response = $this->bitmartClient->getOrderDetail($orderId);

            if (isset($response['data'])) {
                return OrderDto::fromArray($response['data']);
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération de l'ordre", [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getOpenOrders(?string $symbol = null): array
    {
        try {
            $response = $this->bitmartClient->getOpenOrders($symbol);

            if (isset($response['data']['orders'])) {
                return array_map(fn($order) => OrderDto::fromArray($order), $response['data']['orders']);
            }

            return [];
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération des ordres ouverts", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    public function getOrderHistory(string $symbol, int $limit = 100): array
    {
        try {
            $response = $this->bitmartClient->getOrderHistory($symbol, $limit);

            if (isset($response['data']['orders'])) {
                return array_map(fn($order) => OrderDto::fromArray($order), $response['data']['orders']);
            }

            return [];
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la récupération de l'historique des ordres", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return [];
        }
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
