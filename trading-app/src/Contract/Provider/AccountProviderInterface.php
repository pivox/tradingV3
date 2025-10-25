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
    public function getAccountBalance(): float;

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
     * Récupère les frais de trading
     */
    public function getTradingFees(string $symbol): array;
}
