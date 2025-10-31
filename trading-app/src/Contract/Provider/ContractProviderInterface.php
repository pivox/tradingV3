<?php

declare(strict_types=1);

namespace App\Contract\Provider;

use App\Contract\Provider\Dto\ContractDto;

/**
 * Interface pour les providers de contrats
 */
interface ContractProviderInterface
{
    /**
     * Récupère tous les contrats disponibles
     */
    public function getContracts(): array;

    /**
     * Récupère les détails d'un contrat spécifique
     */
    public function getContractDetails(string $symbol): ?ContractDto;

    /**
     * Récupère le dernier prix d'un symbole
     */
    public function getLastPrice(string $symbol): ?float;

    /**
     * Récupère le carnet d'ordres
     */
    public function getOrderBook(string $symbol, int $limit = 50): array;

    /**
     * Récupère les trades récents
     */
    public function getRecentTrades(string $symbol, int $limit = 100): array;

    /**
     * Récupère les mark price klines
     */
    public function getMarkPriceKline(string $symbol, int $step = 1, int $limit = 1, ?int $startTime = null, ?int $endTime = null): array;

    /**
     * Récupère les brackets de levier
     */
    public function getLeverageBrackets(string $symbol): array;

    /**
     * Synchronise les contrats (fetch + upsert en base)
     * 
     * @param array<string>|null $symbols Liste des symboles à synchroniser (null = tous)
     * @return array{upserted: int, total_fetched: int, errors: array<string>}
     */
    public function syncContracts(?array $symbols = null): array;
}
