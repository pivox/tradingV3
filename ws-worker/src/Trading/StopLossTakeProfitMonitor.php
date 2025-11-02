<?php

declare(strict_types=1);

namespace App\Trading;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Moniteur de Stop-Loss et Take-Profit
 * 
 * Responsabilit?s:
 * - Surveiller les positions ouvertes
 * - D?clencher SL/TP automatiquement quand prix atteint
 * - Placer les ordres de cl?ture sur Bitmart
 * - Notifier trading-app des ex?cutions
 */
final class StopLossTakeProfitMonitor
{
    private ?TimerInterface $checkTimer = null;
    
    /**
     * @var array<string,float> Prix actuels par symbole
     */
    private array $currentPrices = [];

    /**
     * @var array<string,array{side:string,quantity:float}> Positions actuelles par symbole
     */
    private array $currentPositions = [];

    public function __construct(
        private readonly OrderQueue $orderQueue,
        private readonly OrderPlacementService $orderPlacement,
        private readonly HttpClientInterface $httpClient,
        private readonly float $slippageTolerancePercent = 0.05, // 0.05% slippage acceptable
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * D?marre le monitoring
     */
    public function start(): void
    {
        if ($this->checkTimer !== null) {
            $this->logger->warning('[SLTP] Monitor already started');
            return;
        }

        // V?rifier toutes les 100ms (SL est urgent)
        $this->checkTimer = Loop::addPeriodicTimer(0.1, function() {
            $this->checkPositions();
        });

        $this->logger->info('[SLTP] Monitor started', [
            'slippage_tolerance' => $this->slippageTolerancePercent,
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
            $this->logger->info('[SLTP] Monitor stopped');
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
     * Met ? jour une position (appel?e depuis le websocket positions)
     */
    public function updatePosition(string $symbol, string $side, float $quantity): void
    {
        if ($quantity > 0) {
            $this->currentPositions[$symbol] = [
                'side' => $side,
                'quantity' => $quantity,
            ];
        } else {
            // Position ferm?e
            unset($this->currentPositions[$symbol]);
        }
    }

    /**
     * V?rifie toutes les positions monitor?es
     */
    private function checkPositions(): void
    {
        $monitoredPositions = $this->orderQueue->getMonitoredPositions();

        foreach ($monitoredPositions as $positionId => $position) {
            $symbol = $position['symbol'];
            $currentPrice = $this->currentPrices[$symbol] ?? null;

            if ($currentPrice === null) {
                continue; // Pas encore de prix pour ce symbole
            }

            // V?rifier si la position existe encore
            if (!isset($this->currentPositions[$symbol])) {
                $this->logger->debug('[SLTP] Position no longer open', [
                    'position_id' => $positionId,
                    'symbol' => $symbol,
                ]);
                $this->orderQueue->removeMonitoredPosition($positionId);
                continue;
            }

            $currentPosition = $this->currentPositions[$symbol];
            $side = $currentPosition['side'];

            // V?rifier Stop-Loss
            if ($position['stop_loss'] !== null) {
                if ($this->shouldTriggerStopLoss($side, $currentPrice, $position['stop_loss'])) {
                    $this->triggerStopLoss($position, $currentPosition, $currentPrice);
                    continue; // Position ferm?e, pas besoin de v?rifier TP
                }
            }

            // V?rifier Take-Profit
            if ($position['take_profit'] !== null) {
                if ($this->shouldTriggerTakeProfit($side, $currentPrice, $position['take_profit'])) {
                    $this->triggerTakeProfit($position, $currentPosition, $currentPrice);
                }
            }
        }
    }

    /**
     * D?termine si le SL doit ?tre d?clench?
     */
    private function shouldTriggerStopLoss(string $side, float $currentPrice, float $stopLoss): bool
    {
        $side = strtolower($side);

        if ($side === 'long' || $side === 'buy') {
            // Pour un long, SL si prix <= stop_loss
            return $currentPrice <= $stopLoss * (1 + $this->slippageTolerancePercent / 100.0);
        } else {
            // Pour un short, SL si prix >= stop_loss
            return $currentPrice >= $stopLoss * (1 - $this->slippageTolerancePercent / 100.0);
        }
    }

    /**
     * D?termine si le TP doit ?tre d?clench?
     */
    private function shouldTriggerTakeProfit(string $side, float $currentPrice, float $takeProfit): bool
    {
        $side = strtolower($side);

        if ($side === 'long' || $side === 'buy') {
            // Pour un long, TP si prix >= take_profit
            return $currentPrice >= $takeProfit * (1 - $this->slippageTolerancePercent / 100.0);
        } else {
            // Pour un short, TP si prix <= take_profit
            return $currentPrice <= $takeProfit * (1 + $this->slippageTolerancePercent / 100.0);
        }
    }

    /**
     * D?clenche le Stop-Loss
     */
    private function triggerStopLoss(array $position, array $currentPosition, float $currentPrice): void
    {
        $this->logger->warning('[SLTP] Stop-Loss triggered', [
            'position_id' => $position['order_id'],
            'symbol' => $position['symbol'],
            'side' => $currentPosition['side'],
            'current_price' => $currentPrice,
            'stop_loss' => $position['stop_loss'],
        ]);

        $this->closePosition($position, $currentPosition, 'stop_loss', $currentPrice);
    }

    /**
     * D?clenche le Take-Profit
     */
    private function triggerTakeProfit(array $position, array $currentPosition, float $currentPrice): void
    {
        $this->logger->info('[SLTP] Take-Profit triggered', [
            'position_id' => $position['order_id'],
            'symbol' => $position['symbol'],
            'side' => $currentPosition['side'],
            'current_price' => $currentPrice,
            'take_profit' => $position['take_profit'],
        ]);

        $this->closePosition($position, $currentPosition, 'take_profit', $currentPrice);
    }

    /**
     * Ferme une position sur Bitmart
     */
    private function closePosition(array $position, array $currentPosition, string $reason, float $currentPrice): void
    {
        // Retirer de la queue de monitoring
        $positionId = array_search($position, $this->orderQueue->getMonitoredPositions());
        if ($positionId !== false) {
            $this->orderQueue->removeMonitoredPosition((string) $positionId);
        }

        $side = strtolower($currentPosition['side']);
        $closeSide = match($side) {
            'long', 'buy' => 'close_long',
            'short', 'sell' => 'close_short',
            default => 'close_long'
        };

        // Mapper vers Bitmart close side
        // 2 = close_short, 3 = close_long
        $bitmartCloseSide = match($closeSide) {
            'close_long' => 3,
            'close_short' => 2,
            default => 3
        };

        $this->logger->info('[SLTP] Closing position', [
            'position_id' => $position['order_id'],
            'symbol' => $position['symbol'],
            'side' => $closeSide,
            'quantity' => $currentPosition['quantity'],
            'reason' => $reason,
        ]);

        // Utiliser market order pour garantir l'ex?cution
        $result = $this->orderPlacement->placeMarketOrder(
            symbol: $position['symbol'],
            side: $closeSide,
            quantity: $currentPosition['quantity'],
            options: [
                'side' => $bitmartCloseSide, // Force le side num?rique pour close
            ]
        );

        if ($result['success']) {
            $this->logger->info('[SLTP] Position closed successfully', [
                'position_id' => $position['order_id'],
                'close_order_id' => $result['order_id'],
                'reason' => $reason,
            ]);

            // Callback vers trading-app
            if ($position['callback_url'] !== null) {
                $this->notifyCallback($position['callback_url'], [
                    'position_id' => $position['order_id'],
                    'close_order_id' => $result['order_id'],
                    'status' => 'closed',
                    'reason' => $reason,
                    'close_price' => $currentPrice,
                ]);
            }
        } else {
            $this->logger->error('[SLTP] Position close failed', [
                'position_id' => $position['order_id'],
                'error' => $result['error'],
            ]);

            // Callback vers trading-app avec erreur
            if ($position['callback_url'] !== null) {
                $this->notifyCallback($position['callback_url'], [
                    'position_id' => $position['order_id'],
                    'status' => 'close_failed',
                    'reason' => $reason,
                    'error' => $result['error'],
                ]);
            }
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
            $this->logger->debug('[SLTP] Callback sent', [
                'url' => $callbackUrl,
                'status' => $data['status'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[SLTP] Callback failed', [
                'url' => $callbackUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retourne le nombre de positions monitor?es
     */
    public function getMonitoredPositionsCount(): int
    {
        return $this->orderQueue->countMonitoredPositions();
    }

    /**
     * Retourne les positions actuelles
     *
     * @return array<string,array>
     */
    public function getCurrentPositions(): array
    {
        return $this->currentPositions;
    }
}
