<?php

declare(strict_types=1);

namespace App\Domain\Strategy\Service;

use App\Domain\Common\Dto\SignalDto;
use App\Domain\Common\Enum\Timeframe;
use App\Domain\Signal\Strategy\StrategyInterface;
use App\Domain\Strategy\Model\StrategyConfig;
use App\Domain\Strategy\Model\StrategyPerformance;

class StrategyEngine
{
    /**
     * @param StrategyInterface[] $strategies
     */
    public function __construct(
        private array $strategies = []
    ) {
    }

    /**
     * Exécute toutes les stratégies actives pour un symbole donné
     */
    public function executeStrategies(
        string $symbol,
        Timeframe $timeframe,
        array $klines,
        array $indicators
    ): array {
        $signals = [];

        foreach ($this->strategies as $strategy) {
            if (!$strategy->isEnabled() || !$strategy->supports($timeframe)) {
                continue;
            }

            $strategySignals = $this->executeStrategy($strategy, $symbol, $timeframe, $klines, $indicators);
            $signals = array_merge($signals, $strategySignals);
        }

        return $signals;
    }

    /**
     * Exécute une stratégie spécifique
     */
    private function executeStrategy(
        StrategyInterface $strategy,
        string $symbol,
        Timeframe $timeframe,
        array $klines,
        array $indicators
    ): array {
        $signals = [];

        foreach ($klines as $kline) {
            $indicatorSnapshot = $indicators[$kline->openTime->format('Y-m-d H:i:s')] ?? null;
            if ($indicatorSnapshot === null) {
                continue;
            }

            $signal = $strategy->generateSignal($symbol, $timeframe, $kline, $indicatorSnapshot);
            if ($signal !== null) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    /**
     * Optimise les paramètres d'une stratégie
     */
    public function optimizeStrategy(
        StrategyInterface $strategy,
        array $historicalData,
        array $performanceMetrics
    ): StrategyConfig {
        // Logique d'optimisation des paramètres
        $bestConfig = new StrategyConfig(
            strategyName: $strategy->getName(),
            parameters: $strategy->getParameters(),
            performance: new StrategyPerformance($performanceMetrics)
        );

        return $bestConfig;
    }

    /**
     * Ajoute une stratégie au moteur
     */
    public function addStrategy(StrategyInterface $strategy): void
    {
        $this->strategies[] = $strategy;
    }

    /**
     * Retire une stratégie du moteur
     */
    public function removeStrategy(string $strategyName): void
    {
        $this->strategies = array_filter(
            $this->strategies,
            fn(StrategyInterface $strategy) => $strategy->getName() !== $strategyName
        );
    }

    /**
     * Retourne les stratégies actives
     */
    public function getActiveStrategies(): array
    {
        return array_filter($this->strategies, fn(StrategyInterface $strategy) => $strategy->isEnabled());
    }

    /**
     * Retourne toutes les stratégies
     */
    public function getAllStrategies(): array
    {
        return $this->strategies;
    }
}


