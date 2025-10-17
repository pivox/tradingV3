<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Port\In;

use App\Domain\Common\Dto\SignalDto;
use App\Domain\Common\Enum\Timeframe;
use App\Domain\Strategy\Model\StrategyConfig;
use App\Domain\Strategy\Model\StrategyPerformance;

interface StrategyExecutionPort
{
    /**
     * Exécute une stratégie pour un symbole donné
     */
    public function executeStrategy(
        string $strategyName,
        string $symbol,
        Timeframe $timeframe
    ): array;

    /**
     * Exécute toutes les stratégies actives
     */
    public function executeAllStrategies(string $symbol, Timeframe $timeframe): array;

    /**
     * Optimise les paramètres d'une stratégie
     */
    public function optimizeStrategy(
        string $strategyName,
        array $historicalData
    ): StrategyConfig;

    /**
     * Évalue la performance d'une stratégie
     */
    public function evaluateStrategy(
        string $strategyName,
        array $historicalData
    ): StrategyPerformance;

    /**
     * Active/désactive une stratégie
     */
    public function toggleStrategy(string $strategyName, bool $enabled): void;

    /**
     * Met à jour la configuration d'une stratégie
     */
    public function updateStrategyConfig(string $strategyName, array $config): void;
}


