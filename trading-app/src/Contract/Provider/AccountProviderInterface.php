<?php

declare(strict_types=1);

namespace App\Contract\Provider;

use App\Contract\Provider\Dto\AccountDto;
use App\Contract\Provider\Dto\PositionDto;

/**
 * Interface pour les providers de compte
 */
interface AccountProviderInterface
{
    /**
     * Récupère les informations du compte
     */
    public function getAccountInfo(): ?AccountDto;

    /**
     * Récupère le solde du compte
     */
    public function getAccountBalance(string $basicCurrency = 'USDT'): float;

    /**
     * Récupère les positions ouvertes
     */
    public function getOpenPositions(?string $symbol = null): array;

    /**
     * Récupère une position spécifique
     */
    public function getPosition(string $symbol): ?PositionDto;

    /**
     * Récupère l'historique des trades
     */
    public function getTradeHistory(string $symbol, int $limit = 100): array;

    /**
     * Récupère les trades (fills) pour un symbole
     * Utilise GET /contract/private/trades
     * 
     * @param string|null $symbol Symbole (optionnel, si null retourne tous les symboles)
     * @param int $limit Limite de résultats
     * @param int|null $startTime Timestamp de début en secondes (optionnel)
     * @param int|null $endTime Timestamp de fin en secondes (optionnel)
     * @return array Tableau de trades
     */
    public function getTrades(?string $symbol = null, int $limit = 100, ?int $startTime = null, ?int $endTime = null): array;

    /**
     * Récupère l'historique des transactions (PnL réalisé, funding, etc.)
     * Utilise GET /contract/private/transaction-history
     * 
     * @param string|null $symbol Symbole (optionnel)
     * @param int|null $flowType Type de flux: 2=realized PnL, 3=funding, etc. (optionnel)
     * @param int $limit Limite de résultats
     * @param int|null $startTime Timestamp de début en secondes (optionnel)
     * @param int|null $endTime Timestamp de fin en secondes (optionnel)
     * @return array Tableau de transactions
     */
    public function getTransactionHistory(?string $symbol = null, ?int $flowType = null, int $limit = 100, ?int $startTime = null, ?int $endTime = null): array;

    /**
     * Récupère les frais de trading
     */
    public function getTradingFees(string $symbol): array;
}
