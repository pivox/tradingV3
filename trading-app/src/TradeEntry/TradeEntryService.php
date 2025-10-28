<?php

namespace App\TradeEntry;

use App\Domain\Trade\Entity\Trade;
use App\Domain\Trade\Repository\TradeRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de gestion des entrées de trading
 * Centralise la logique de création et validation des trades
 */
class TradeEntryService
{
    public function __construct(
        private TradeRepositoryInterface $tradeRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    /**
     * Crée une nouvelle entrée de trade
     */
    public function createTradeEntry(array $data): Trade
    {
        $this->validateTradeData($data);

        $trade = new Trade();
        $trade->setSymbol($data['symbol']);
        $trade->setSide($data['side']);
        $trade->setType($data['type']);
        $trade->setQuantity($data['quantity']);
        $trade->setPrice($data['price']);
        $trade->setStatus('pending');
        $trade->setCreatedAt(new \DateTime());
        $trade->setUpdatedAt(new \DateTime());

        if (isset($data['stop_loss'])) {
            $trade->setStopLoss($data['stop_loss']);
        }

        if (isset($data['take_profit'])) {
            $trade->setTakeProfit($data['take_profit']);
        }

        if (isset($data['notes'])) {
            $trade->setNotes($data['notes']);
        }

        $this->entityManager->persist($trade);
        $this->entityManager->flush();

        $this->logger->info("Entrée de trade créée", [
            'trade_id' => $trade->getId(),
            'symbol' => $trade->getSymbol(),
            'side' => $trade->getSide(),
            'quantity' => $trade->getQuantity(),
            'price' => $trade->getPrice()
        ]);

        return $trade;
    }

    /**
     * Valide les données d'un trade
     */
    private function validateTradeData(array $data): void
    {
        $requiredFields = ['symbol', 'side', 'type', 'quantity', 'price'];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \InvalidArgumentException("Le champ '{$field}' est requis");
            }
        }

        if (!in_array($data['side'], ['BUY', 'SELL'])) {
            throw new \InvalidArgumentException("Le côté doit être 'BUY' ou 'SELL'");
        }

        if (!in_array($data['type'], ['MARKET', 'LIMIT', 'STOP', 'STOP_LIMIT'])) {
            throw new \InvalidArgumentException("Type de trade invalide");
        }

        if ($data['quantity'] <= 0) {
            throw new \InvalidArgumentException("La quantité doit être positive");
        }

        if ($data['price'] <= 0) {
            throw new \InvalidArgumentException("Le prix doit être positif");
        }
    }

    /**
     * Met à jour le statut d'un trade
     */
    public function updateTradeStatus(Trade $trade, string $status): void
    {
        $oldStatus = $trade->getStatus();
        $trade->setStatus($status);
        $trade->setUpdatedAt(new \DateTime());

        $this->entityManager->persist($trade);
        $this->entityManager->flush();

        $this->logger->info("Statut du trade mis à jour", [
            'trade_id' => $trade->getId(),
            'old_status' => $oldStatus,
            'new_status' => $status
        ]);
    }

    /**
     * Annule un trade
     */
    public function cancelTrade(Trade $trade, ?string $reason = null): void
    {
        $trade->setStatus('cancelled');
        $trade->setUpdatedAt(new \DateTime());
        
        if ($reason) {
            $trade->setNotes($trade->getNotes() . "\nAnnulé: " . $reason);
        }

        $this->entityManager->persist($trade);
        $this->entityManager->flush();

        $this->logger->info("Trade annulé", [
            'trade_id' => $trade->getId(),
            'reason' => $reason
        ]);
    }

    /**
     * Récupère les trades en attente
     */
    public function getPendingTrades(): array
    {
        return $this->tradeRepository->findBy(['status' => 'pending']);
    }

    /**
     * Récupère les trades par symbole
     */
    public function getTradesBySymbol(string $symbol): array
    {
        return $this->tradeRepository->findBy(['symbol' => $symbol]);
    }

    /**
     * Récupère les trades actifs
     */
    public function getActiveTrades(): array
    {
        return $this->tradeRepository->findBy(['status' => 'active']);
    }

    /**
     * Calcule le P&L d'un trade
     */
    public function calculatePnL(Trade $trade, float $currentPrice): float
    {
        $entryPrice = $trade->getPrice();
        $quantity = $trade->getQuantity();
        
        if ($trade->getSide() === 'BUY') {
            return ($currentPrice - $entryPrice) * $quantity;
        } else {
            return ($entryPrice - $currentPrice) * $quantity;
        }
    }

    /**
     * Vérifie si un trade doit être fermé
     */
    public function shouldCloseTrade(Trade $trade, float $currentPrice): bool
    {
        if ($trade->getStatus() !== 'active') {
            return false;
        }

        $pnl = $this->calculatePnL($trade, $currentPrice);

        // Vérifier le stop loss
        if ($trade->getStopLoss() && $pnl <= -abs($trade->getStopLoss())) {
            return true;
        }

        // Vérifier le take profit
        if ($trade->getTakeProfit() && $pnl >= $trade->getTakeProfit()) {
            return true;
        }

        return false;
    }

    /**
     * Récupère les statistiques des trades
     */
    public function getTradeStats(): array
    {
        $totalTrades = $this->tradeRepository->count([]);
        $activeTrades = $this->tradeRepository->count(['status' => 'active']);
        $pendingTrades = $this->tradeRepository->count(['status' => 'pending']);
        $completedTrades = $this->tradeRepository->count(['status' => 'completed']);
        $cancelledTrades = $this->tradeRepository->count(['status' => 'cancelled']);

        return [
            'total_trades' => $totalTrades,
            'active_trades' => $activeTrades,
            'pending_trades' => $pendingTrades,
            'completed_trades' => $completedTrades,
            'cancelled_trades' => $cancelledTrades
        ];
    }
}
