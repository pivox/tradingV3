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
use App\Provider\Repository\ContractRepository;
use App\Service\FuturesOrderSyncService;
use App\Service\OrderIntentManager;
use App\Entity\OrderIntent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        private readonly LoggerInterface $logger,
        private readonly ?FuturesOrderSyncService $syncService = null,
        private readonly ?OrderIntentManager $intentManager = null,
        private readonly ?ContractRepository $contractRepository = null,
        private readonly ?HttpClientInterface $httpClient = null,
        #[Autowire('%env(WS_AGENT_BASE_URI)%')]
        private readonly ?string $wsAgentBaseUri = null,
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
        $intent = null;
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

            // Créer OrderIntent avant l'envoi si le service est disponible
            if ($this->intentManager) {
                $quantization = $this->extractQuantization($symbol);
                $rawInputs = [
                    'symbol' => $symbol,
                    'side' => $side->value,
                    'type' => $type->value,
                    'quantity' => $quantity,
                    'price' => $price,
                    'stopPrice' => $stopPrice,
                    'options' => $options,
                ];

                $intent = $this->intentManager->createIntent($payload, $quantization, $rawInputs);

                // Valider l'intent (validation basique)
                $validationErrors = $this->validateOrderParams($payload);
                if (!$this->intentManager->validateIntent($intent, $validationErrors)) {
                    // Validation échouée, mais on continue quand même l'envoi
                    $this->logger->warning('[BitmartOrderProvider] Intent validation failed but continuing', [
                        'client_order_id' => $intent->getClientOrderId(),
                        'errors' => $validationErrors,
                    ]);
                }

                $this->intentManager->markReadyToSend($intent);
            }

            $orderPayload = $payload;
            if (isset($orderPayload['leverage'])) {
                unset($orderPayload['leverage']);
            }

            $response = $this->bitmartClient->submitOrder($orderPayload);

            if (isset($response['data']['order_id'])) {
                // Bitmart may return order_id as int; cast to string for DTO expectations
                $orderId = (string) $response['data']['order_id'];

                // Marquer l'intent comme SENT
                if ($intent && $this->intentManager) {
                    try {
                        $this->intentManager->markAsSent($intent, $orderId);
                    } catch (\Throwable $e) {
                        $this->logger->warning('[BitmartOrderProvider] Failed to mark intent as sent', [
                            'order_id' => $orderId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Synchroniser la réponse de soumission si disponible
                if ($this->syncService && isset($response['data'])) {
                    try {
                        $this->syncService->syncOrderFromApi($response['data']);
                    } catch (\Throwable $e) {
                        // Log mais ne pas bloquer le flux
                        $this->logger->warning('[BitmartOrderProvider] Failed to sync order on submit', [
                            'order_id' => $orderId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

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

            // Marquer l'intent comme FAILED si pas encore marqué
            if ($intent && $this->intentManager) {
                try {
                    $this->intentManager->markAsFailed($intent, 'Order submission returned no order_id');
                } catch (\Throwable $e) {
                    $this->logger->warning('[BitmartOrderProvider] Failed to mark intent as failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return null;
        } catch (\Exception $e) {
            // Marquer l'intent comme FAILED en cas d'exception
            if ($intent && $this->intentManager) {
                try {
                    $this->intentManager->markAsFailed($intent, $e->getMessage());
                } catch (\Throwable $innerE) {
                    // Ignore
                }
            }

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
        // Trouver l'OrderIntent associé si disponible
        $intent = null;
        if ($this->intentManager) {
            $intent = $this->intentManager->findIntent(orderId: $orderId);
        }

        $lastError = null;
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $response = $this->bitmartClient->cancelOrder($orderId);
                $success = isset($response['data']['result']) && $response['data']['result'] === 'success';

                // Marquer l'intent comme CANCELLED si succès
                if ($success && $intent && $this->intentManager) {
                    try {
                        $this->intentManager->markAsCancelled($intent);
                    } catch (\Throwable $e) {
                        $this->logger->warning('[BitmartOrderProvider] Failed to mark intent as cancelled', [
                            'order_id' => $orderId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                return $success;
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

    /**
     * Extrait les données de quantification depuis le contrat
     * @return array<string,mixed>
     */
    private function extractQuantization(string $symbol): array
    {
        if (!$this->contractRepository) {
            return [];
        }

        $contract = $this->contractRepository->findBySymbol(strtoupper($symbol));
        if (!$contract) {
            return [];
        }

        // Utiliser la réflexion pour accéder à volPrecision si nécessaire
        // ou simplement omettre si le getter n'existe pas
        $quantization = [
            'tick_size' => $contract->getTickSize(),
            'price_precision' => $contract->getPricePrecision(),
            'min_volume' => $contract->getMinVolume(),
            'max_volume' => $contract->getMaxVolume(),
            'market_max_volume' => $contract->getMarketMaxVolume(),
            'contract_size' => $contract->getContractSize(),
            'min_size' => $contract->getMinSize(),
            'max_size' => $contract->getMaxSize(),
        ];

        // Ajouter vol_precision via réflexion si disponible
        try {
            $reflection = new \ReflectionClass($contract);
            if ($reflection->hasProperty('volPrecision')) {
                $property = $reflection->getProperty('volPrecision');
                $property->setAccessible(true);
                $quantization['vol_precision'] = $property->getValue($contract);
            }
        } catch (\Throwable $e) {
            // Ignorer si la réflexion échoue
        }

        return $quantization;
    }

    /**
     * Valide les paramètres de l'ordre
     * @param array<string,mixed> $payload
     * @return array<string,string>|null Erreurs de validation, null si valide
     */
    private function validateOrderParams(array $payload): ?array
    {
        $errors = [];

        if (empty($payload['symbol'])) {
            $errors['symbol'] = 'Symbol is required';
        }

        if (empty($payload['side']) || !in_array($payload['side'], [1, 2, 3, 4], true)) {
            $errors['side'] = 'Invalid side value';
        }

        if (empty($payload['type']) || !in_array($payload['type'], ['limit', 'market'], true)) {
            $errors['type'] = 'Invalid type value';
        }

        if (empty($payload['size']) || $payload['size'] <= 0) {
            $errors['size'] = 'Size must be positive';
        }

        if ($payload['type'] === 'limit' && empty($payload['price'])) {
            $errors['price'] = 'Price is required for limit orders';
        }

        return count($errors) > 0 ? $errors : null;
    }

    public function getOrder(string $orderId): ?OrderDto
    {
        $lastError = null;
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $response = $this->bitmartClient->getOrderDetail($orderId);
                if (isset($response['data'])) {
                    // Synchroniser l'ordre dans la base de données
                    if ($this->syncService) {
                        try {
                            $this->syncService->syncOrderFromApi($response['data']);
                        } catch (\Throwable $e) {
                            // Log mais ne pas bloquer le flux
                            $this->logger->warning('[BitmartOrderProvider] Failed to sync order detail', [
                                'order_id' => $orderId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
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
                
                // Log pour debug : structure de la réponse
                $this->logger->debug('[BitmartOrderProvider] Open orders response structure', [
                    'symbol' => $symbol,
                    'has_data' => isset($response['data']),
                    'data_keys' => isset($response['data']) ? array_keys($response['data']) : [],
                    'code' => $response['code'] ?? null,
                    'message' => $response['message'] ?? null,
                    'attempt' => $i + 1,
                ]);
                
                // BitMart peut retourner soit data.orders soit data directement comme tableau
                $orders = null;
                if (isset($response['data']['orders']) && is_array($response['data']['orders'])) {
                    $orders = $response['data']['orders'];
                } elseif (isset($response['data']) && is_array($response['data'])) {
                    // Si data est directement un tableau (peut être vide ou contenir des ordres)
                    if (!empty($response['data']) && isset($response['data'][0]) && is_array($response['data'][0])) {
                        // Vérifier si c'est un tableau d'ordres (premier élément a order_id)
                        if (isset($response['data'][0]['order_id'])) {
                            $orders = $response['data'];
                        }
                    }
                    // Si data est un tableau vide, orders reste null et on retourne []
                }
                
                if ($orders !== null) {
                    // Synchroniser tous les ordres ouverts
                    if ($this->syncService) {
                        foreach ($orders as $orderData) {
                            try {
                                $this->syncService->syncOrderFromApi($orderData);
                            } catch (\Throwable $e) {
                                // Log mais ne pas bloquer le flux
                                $this->logger->warning('[BitmartOrderProvider] Failed to sync open order', [
                                    'order_id' => $orderData['order_id'] ?? null,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                    return array_map(fn($order) => OrderDto::fromArray($order), $orders);
                }
                
                // Si code 30000 ou data vide, c'est normal (pas d'ordres)
                if ((isset($response['code']) && (int) $response['code'] === 30000) 
                    || (isset($response['data']) && is_array($response['data']) && empty($response['data']))) {
                    $this->logger->debug('[BitmartOrderProvider] No open orders', [
                        'symbol' => $symbol,
                        'code' => $response['code'] ?? null,
                    ]);
                    return [];
                }
                
                $lastError = new \RuntimeException('Invalid open orders response structure. Response: ' . json_encode($response));
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
                'error' => $lastError->getMessage(),
                'trace' => $lastError->getTraceAsString(),
            ]);
        }
        return [];
    }

    /**
     * Récupère les ordres planifiés (Plan Orders) incluant les TP/SL
     * 
     * @param string|null $symbol Symbole à filtrer (optionnel)
     * @return array Tableau d'ordres planifiés
     */
    public function getPlanOrders(?string $symbol = null): array
    {
        $lastError = null;
        for ($i = 0; $i < self::RETRY_ATTEMPTS; $i++) {
            try {
                $response = $this->bitmartClient->getPlanOrders($symbol);
                
                $this->logger->debug('[BitmartOrderProvider] Plan orders response structure', [
                    'symbol' => $symbol,
                    'has_data' => isset($response['data']),
                    'data_keys' => isset($response['data']) ? array_keys($response['data']) : [],
                    'code' => $response['code'] ?? null,
                    'message' => $response['message'] ?? null,
                    'attempt' => $i + 1,
                ]);
                
                // BitMart peut retourner soit data.orders soit data directement comme tableau
                $orders = null;
                if (isset($response['data']['orders']) && is_array($response['data']['orders'])) {
                    $orders = $response['data']['orders'];
                } elseif (isset($response['data']) && is_array($response['data'])) {
                    if (!empty($response['data']) && isset($response['data'][0]) && is_array($response['data'][0])) {
                        if (isset($response['data'][0]['order_id']) || isset($response['data'][0]['plan_order_id'])) {
                            $orders = $response['data'];
                        }
                    }
                }
                
                if ($orders !== null) {
                    // Les plan orders peuvent avoir une structure légèrement différente
                    // On retourne les données brutes pour l'instant car OrderDto pourrait ne pas correspondre
                    return $orders;
                }
                
                // Si code 30000 ou data vide, c'est normal (pas d'ordres)
                if ((isset($response['code']) && (int) $response['code'] === 30000) 
                    || (isset($response['data']) && is_array($response['data']) && empty($response['data']))) {
                    $this->logger->debug('[BitmartOrderProvider] No plan orders', [
                        'symbol' => $symbol,
                        'code' => $response['code'] ?? null,
                    ]);
                    return [];
                }
                
                $lastError = new \RuntimeException('Invalid plan orders response structure. Response: ' . json_encode($response));
            } catch (\Throwable $e) {
                $lastError = $e;
            }

            if ($i < self::RETRY_ATTEMPTS - 1) {
                usleep(self::RETRY_SLEEP_US);
            }
        }
        if ($lastError) {
            $this->logger->error("Erreur lors de la récupération des ordres planifiés", [
                'symbol' => $symbol,
                'error' => $lastError->getMessage(),
                'trace' => $lastError->getTraceAsString(),
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
                
                // BitMart peut retourner soit data.orders soit data directement comme tableau
                $orders = null;
                if (isset($response['data']['orders']) && is_array($response['data']['orders'])) {
                    $orders = $response['data']['orders'];
                } elseif (isset($response['data']) && is_array($response['data'])) {
                    // Si data est directement un tableau (peut être vide ou contenir des ordres)
                    if (!empty($response['data']) && isset($response['data'][0]) && is_array($response['data'][0])) {
                        // Vérifier si c'est un tableau d'ordres (premier élément a order_id)
                        if (isset($response['data'][0]['order_id'])) {
                            $orders = $response['data'];
                        }
                    }
                }
                
                if ($orders !== null) {
                    // Synchroniser tous les ordres de l'historique
                    if ($this->syncService) {
                        foreach ($orders as $orderData) {
                            try {
                                $this->syncService->syncOrderFromApi($orderData);
                            } catch (\Throwable $e) {
                                // Log mais ne pas bloquer le flux
                                $this->logger->warning('[BitmartOrderProvider] Failed to sync history order', [
                                    'order_id' => $orderData['order_id'] ?? null,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                    return array_map(fn($order) => OrderDto::fromArray($order), $orders);
                }
                
                $lastError = new \RuntimeException('Invalid order history response structure. Expected data.orders or data as array.');
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

    /**
     * Programme l'annulation automatique des ordres après un délai (cancel-all-after).
     */
    public function cancelAllAfter(string $symbol, int $timeoutSeconds): bool
    {
        try {
            $response = $this->bitmartClient->cancelAllAfter($symbol, $timeoutSeconds);
            return isset($response['code']) && (int)$response['code'] === 1000;
        } catch (\Exception $e) {
            $this->logger->error("Erreur lors de la programmation de l'annulation automatique", [
                'symbol' => $symbol,
                'timeout' => $timeoutSeconds,
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
