<?php

namespace App\Runtime\Store;

use App\Domain\Signal\Entity\Signal;
use App\Domain\Signal\Repository\SignalRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service de gestion des signaux en runtime
 * Fournit des méthodes optimisées pour la récupération et le stockage des signaux
 */
class SignalService
{
    public function __construct(
        private SignalRepositoryInterface $signalRepository,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Récupère les signaux actifs pour un symbole donné
     */
    public function getActiveSignalsForSymbol(string $symbol): array
    {
        return $this->signalRepository->findActiveBySymbol($symbol);
    }

    /**
     * Récupère les signaux par type et statut
     */
    public function getSignalsByTypeAndStatus(string $type, string $status): array
    {
        return $this->signalRepository->findByTypeAndStatus($type, $status);
    }

    /**
     * Met à jour le statut d'un signal
     */
    public function updateSignalStatus(Signal $signal, string $status): void
    {
        $signal->setStatus($status);
        $signal->setUpdatedAt(new \DateTime());
        
        $this->entityManager->persist($signal);
        $this->entityManager->flush();
    }

    /**
     * Crée un nouveau signal
     */
    public function createSignal(array $data): Signal
    {
        $signal = new Signal();
        $signal->setSymbol($data['symbol']);
        $signal->setType($data['type']);
        $signal->setStatus($data['status'] ?? 'pending');
        $signal->setPrice($data['price']);
        $signal->setQuantity($data['quantity']);
        $signal->setCreatedAt(new \DateTime());
        $signal->setUpdatedAt(new \DateTime());

        $this->entityManager->persist($signal);
        $this->entityManager->flush();

        return $signal;
    }

    /**
     * Supprime un signal
     */
    public function deleteSignal(Signal $signal): void
    {
        $this->entityManager->remove($signal);
        $this->entityManager->flush();
    }

    /**
     * Récupère les signaux expirés
     */
    public function getExpiredSignals(): array
    {
        return $this->signalRepository->findExpired();
    }

    /**
     * Nettoie les signaux expirés
     */
    public function cleanupExpiredSignals(): int
    {
        $expiredSignals = $this->getExpiredSignals();
        $count = count($expiredSignals);

        foreach ($expiredSignals as $signal) {
            $this->entityManager->remove($signal);
        }

        $this->entityManager->flush();
        
        return $count;
    }

    /**
     * Récupère les statistiques des signaux
     */
    public function getSignalStats(): array
    {
        return [
            'total_signals' => $this->signalRepository->count([]),
            'active_signals' => $this->signalRepository->count(['status' => 'active']),
            'pending_signals' => $this->signalRepository->count(['status' => 'pending']),
            'expired_signals' => count($this->getExpiredSignals())
        ];
    }
}
