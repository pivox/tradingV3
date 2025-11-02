<?php

declare(strict_types=1);

namespace App\Trading;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Moniteur de zones d'entr?e (entry zone)
 * 
 * Responsabilit?s:
 * - Surveiller les prix en temps r?el via websocket
 * - D?tecter quand le prix entre dans une entry zone
 * - Placer l'ordre automatiquement au bon moment
 * - G?rer la strat?gie "immediate if in zone" ou "wait"
 */
final class EntryZoneMonitor
{
    private ?TimerInterface $checkTimer = null;
    
    /**
     * @var array<string,float> Prix actuels par symbole
     */
    private array $currentPrices = [];

    public function __construct(
        private readonly OrderQueue $orderQueue,
        private readonly OrderPlacementService $orderPlacement,
        private readonly HttpClientInterface $httpClient,
        private readonly float $tolerancePercent = 0.1, // 0.1% tolerance
        private readonly bool $immediateIfInZone = true, // Place imm?diatement si d?j? dans la zone
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * D?marre le monitoring
     */
    public function start(): void
    {
        if ($this->checkTimer !== null) {
            $this->logger->warning('[EntryZone] Monitor already started');
            return;
        }

        // V?rifier les ordres toutes les 100ms
        $this->checkTimer = Loop::addPeriodicTimer(0.1, function() {
            $this->checkOrders();
            $this->checkExpiredOrders();
        });

        $this->logger->info('[EntryZone] Monitor started', [
            'tolerance_percent' => $this->tolerancePercent,
            'immediate_if_in_zone' => $this->immediateIfInZone,
        ]);
    }

    /**
     * Arr?te le monitoring
     */
    public function stop(): void
    {
        if ($this->checkTimer !== null) {
            Loop::cancelTimer($this->checkTimer);
            $this->checkTimer = null;
            $this->logger->info('[EntryZone] Monitor stopped');
        }
    }

    /**
     * Met ? jour le prix d'un symbole (appel? depuis le websocket)
     */
    public function updatePrice(string $symbol, float $price): void
    {
        $this->currentPrices[$symbol] = $price;
    }

    /**
     * V?rifie tous les ordres en attente
     */
    private function checkOrders(): void
    {
        $pendingOrders = $this->orderQueue->getPendingOrders();

        foreach ($pendingOrders as $orderId => $order) {
            $symbol = $order['symbol'];
            $currentPrice = $this->currentPrices[$symbol] ?? null;

            if ($currentPrice === null) {
                continue; // Pas encore de prix pour ce symbole
            }

            if ($this->shouldPlaceOrder($order, $currentPrice)) {
                $this->placeOrder($order, $currentPrice);
            }
        }
    }

    /**
     * V?rifie les ordres expir?s et les annule
     */
    private function checkExpiredOrders(): void
    {
        $expiredOrders = $this->orderQueue->getExpiredOrders();

        foreach ($expiredOrders as $order) {
            $this->logger->warning('[EntryZone] Order timeout', [
                'order_id' => $order['id'],
                'symbol' => $order['symbol'],
            ]);

            // Callback vers trading-app pour notifier le timeout
            if ($order['callback_url'] !== null) {
                $this->notifyCallback($order['callback_url'], [
                    'order_id' => $order['id'],
                    'status' => 'timeout',
                    'reason' => 'Entry zone not reached within timeout',
                ]);
            }
        }
    }

    /**
     * D?termine si un ordre doit ?tre plac?
     */
    private function shouldPlaceOrder(array $order, float $currentPrice): bool
    {
        $minPrice = $order['entry_zone_min'];
        $maxPrice = $order['entry_zone_max'];

        // Appliquer la tol?rance
        $tolerance = $this->tolerancePercent / 100.0;
        $minPriceWithTolerance = $minPrice * (1 - $tolerance);
        $maxPriceWithTolerance = $maxPrice * (1 + $tolerance);

        // V?rifier si le prix est dans la zone (avec tol?rance)
        $inZone = $currentPrice >= $minPriceWithTolerance 
                  && $currentPrice <= $maxPriceWithTolerance;

        if (!$inZone) {
            return false;
        }

        $this->logger->debug('[EntryZone] Price in entry zone', [
            'order_id' => $order['id'],
            'symbol' => $order['symbol'],
            'current_price' => $currentPrice,
            'entry_zone' => [$minPrice, $maxPrice],
        ]);

        return true;
    }

    /**
     * Place un ordre sur Bitmart
     */
    private function placeOrder(array $order, float $currentPrice): void
    {
        // Retirer de la queue avant de placer (?viter les doublons)
        $this->orderQueue->removeOrder($order['id']);

        // Calculer le meilleur prix d'entr?e
        // Pour un long: viser le bas de la zone
        // Pour un short: viser le haut de la zone
        $entryPrice = $this->calculateBestEntryPrice($order, $currentPrice);

        $this->logger->info('[EntryZone] Placing order', [
            'order_id' => $order['id'],
            'symbol' => $order['symbol'],
            'side' => $order['side'],
            'entry_price' => $entryPrice,
            'quantity' => $order['quantity'],
        ]);

        $options = [
            'client_order_id' => $order['id'],
            'post_only' => true, // Maker order pour ?viter taker fees
        ];

        if ($order['leverage'] !== null) {
            $options['leverage'] = $order['leverage'];
        }

        if ($order['stop_loss'] !== null) {
            $options['preset_stop_loss_price'] = $order['stop_loss'];
        }

        if ($order['take_profit'] !== null) {
            $options['preset_take_profit_price'] = $order['take_profit'];
        }

        $result = $this->orderPlacement->placeLimitOrder(
            symbol: $order['symbol'],
            side: $order['side'],
            price: $entryPrice,
            quantity: $order['quantity'],
            options: $options
        );

        if ($result['success']) {
            $this->logger->info('[EntryZone] Order placed successfully', [
                'order_id' => $order['id'],
                'exchange_order_id' => $result['order_id'],
            ]);

            // Si SL/TP d?finis, ajouter ? la queue de monitoring
            if ($order['stop_loss'] !== null || $order['take_profit'] !== null) {
                $this->orderQueue->addMonitoredPosition([
                    'id' => $order['id'],
                    'symbol' => $order['symbol'],
                    'order_id' => $result['order_id'],
                    'stop_loss' => $order['stop_loss'],
                    'take_profit' => $order['take_profit'],
                    'callback_url' => $order['callback_url'],
                ]);
            }

            // Callback vers trading-app
            if ($order['callback_url'] !== null) {
                $this->notifyCallback($order['callback_url'], [
                    'order_id' => $order['id'],
                    'exchange_order_id' => $result['order_id'],
                    'status' => 'placed',
                    'entry_price' => $entryPrice,
                    'quantity' => $order['quantity'],
                ]);
            }
        } else {
            $this->logger->error('[EntryZone] Order placement failed', [
                'order_id' => $order['id'],
                'error' => $result['error'],
            ]);

            // Callback vers trading-app avec erreur
            if ($order['callback_url'] !== null) {
                $this->notifyCallback($order['callback_url'], [
                    'order_id' => $order['id'],
                    'status' => 'failed',
                    'error' => $result['error'],
                ]);
            }
        }
    }

    /**
     * Calcule le meilleur prix d'entr?e selon la strat?gie
     */
    private function calculateBestEntryPrice(array $order, float $currentPrice): float
    {
        $minPrice = $order['entry_zone_min'];
        $maxPrice = $order['entry_zone_max'];
        $side = strtolower($order['side']);

        if ($this->immediateIfInZone) {
            // Si d?j? dans la zone, utiliser le prix actuel
            if ($currentPrice >= $minPrice && $currentPrice <= $maxPrice) {
                return $currentPrice;
            }
        }

        // Sinon, viser le meilleur prix selon le side
        if ($side === 'long' || $side === 'buy') {
            // Pour un long, viser le bas de la zone
            return $minPrice;
        } else {
            // Pour un short, viser le haut de la zone
            return $maxPrice;
        }
    }

    /**
     * Notifie trading-app via callback HTTP
     */
    private function notifyCallback(string $callbackUrl, array $data): void
    {
        try {
            $this->httpClient->request('POST', $callbackUrl, [
                'json' => $data,
                'timeout' => 5.0,
            ]);
            $this->logger->debug('[EntryZone] Callback sent', [
                'url' => $callbackUrl,
                'status' => $data['status'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[EntryZone] Callback failed', [
                'url' => $callbackUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retourne le nombre d'ordres en attente
     */
    public function getPendingOrdersCount(): int
    {
        return $this->orderQueue->count();
    }

    /**
     * Retourne les prix actuels
     *
     * @return array<string,float>
     */
    public function getCurrentPrices(): array
    {
        return $this->currentPrices;
    }
}
