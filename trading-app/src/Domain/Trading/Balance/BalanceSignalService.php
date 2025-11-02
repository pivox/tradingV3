<?php

declare(strict_types=1);

namespace App\Domain\Trading\Balance;

use App\Domain\Trading\Balance\Dto\WorkerBalanceSignalDto;
use Psr\Log\LoggerInterface;

/**
 * Service pour traiter les signaux de balance reçus du ws-worker.
 */
final class BalanceSignalService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Traite un signal de balance reçu du ws-worker.
     * 
     * Pour l'instant, ce service log simplement les informations.
     * Dans le futur, il pourra :
     * - Persister les données dans une table AccountBalance
     * - Déclencher des alertes si le balance est trop bas
     * - Calculer des métriques de performance
     */
    public function process(WorkerBalanceSignalDto $signal): void
    {
        $this->logger->info('[BalanceSignal] Received balance update', [
            'asset' => $signal->asset,
            'available_balance' => $signal->availableBalance,
            'frozen_balance' => $signal->frozenBalance,
            'equity' => $signal->equity,
            'unrealized_pnl' => $signal->unrealizedPnl,
            'position_deposit' => $signal->positionDeposit,
            'timestamp' => $signal->timestamp->format(\DateTimeInterface::ATOM),
            'trace_id' => $signal->traceId,
            'retry_count' => $signal->retryCount,
        ]);

        // TODO: Implémenter la logique de persistance si nécessaire
        // Par exemple :
        // - Créer une entité AccountBalance
        // - Sauvegarder dans la base de données
        // - Vérifier les seuils d'alerte
        // - Publier un événement pour les autres services

        // Pour le moment, on se contente de logger
        // Le fait que le signal arrive ici signifie que :
        // 1. La signature HMAC est valide
        // 2. Le DTO a été validé correctement
        // 3. Le ws-worker fonctionne correctement
    }

    /**
     * Vérifie si le balance disponible est suffisant pour trader.
     * 
     * @param float $minBalance Balance minimum requis en USDT
     */
    public function hasMinimumBalance(WorkerBalanceSignalDto $signal, float $minBalance): bool
    {
        return $signal->getAvailableBalanceFloat() >= $minBalance;
    }

    /**
     * Calcule le pourcentage du balance qui est gelé.
     */
    public function getFrozenPercentage(WorkerBalanceSignalDto $signal): float
    {
        $equity = $signal->getEquityFloat();
        
        if ($equity <= 0.0) {
            return 0.0;
        }

        $frozen = $signal->getFrozenBalanceFloat();
        return ($frozen / $equity) * 100.0;
    }
}

