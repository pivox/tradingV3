<?php

namespace App\Indicator\Context;

/**
 * Exemple d'utilisation du IndicatorContextBuilder corrigé.
 * Montre comment utiliser toutes les nouvelles fonctionnalités.
 */
class IndicatorContextBuilderExample
{
    public function __construct(
        private readonly IndicatorContextBuilder $contextBuilder
    ) {}

    /**
     * Exemple d'utilisation basique avec les paramètres par défaut.
     */
    public function basicExample(): array
    {
        return $this->contextBuilder
            ->symbol('BTCUSDT')
            ->timeframe('1h')
            ->closes([50000, 50100, 50200, 50300, 50400])
            ->highs([50100, 50200, 50300, 50400, 50500])
            ->lows([49900, 50000, 50100, 50200, 50300])
            ->volumes([1000, 1100, 1200, 1300, 1400])
            ->withDefaults() // Configure les paramètres par défaut
            ->build();
    }

    /**
     * Exemple avec des paramètres personnalisés pour les conditions.
     */
    public function customParametersExample(): array
    {
        return $this->contextBuilder
            ->symbol('ETHUSDT')
            ->timeframe('4h')
            ->closes([3000, 3010, 3020, 3030, 3040])
            ->highs([3010, 3020, 3030, 3040, 3050])
            ->lows([2990, 3000, 3010, 3020, 3030])
            ->volumes([500, 550, 600, 650, 700])
            ->entryPrice(3025.0)           // Prix d'entrée pour AtrStopValidCondition
            ->stopLoss(3000.0)             // Stop loss pour AtrStopValidCondition
            ->atrK(2.0)                    // Multiplicateur ATR personnalisé
            ->minAtrPct(0.002)             // 0.2% minimum ATR
            ->maxAtrPct(0.025)             // 2.5% maximum ATR
            ->rsiLt70Threshold(75.0)       // Seuil RSI personnalisé
            ->rsiCrossUpLevel(25.0)        // Niveau de croisement RSI up
            ->rsiCrossDownLevel(75.0)      // Niveau de croisement RSI down
            ->build();
    }

    /**
     * Exemple avec des données de position pour les conditions de trading.
     */
    public function tradingPositionExample(): array
    {
        return $this->contextBuilder
            ->symbol('ADAUSDT')
            ->timeframe('15m')
            ->closes([0.45, 0.46, 0.47, 0.48, 0.49])
            ->highs([0.46, 0.47, 0.48, 0.49, 0.50])
            ->lows([0.44, 0.45, 0.46, 0.47, 0.48])
            ->volumes([10000, 11000, 12000, 13000, 14000])
            ->entryPrice(0.475)            // Prix d'entrée de la position
            ->stopLoss(0.460)              // Stop loss calculé
            ->atrK(1.8)                    // Multiplicateur ATR pour le stop
            ->build();
    }

    /**
     * Exemple minimal sans paramètres personnalisés.
     */
    public function minimalExample(): array
    {
        return $this->contextBuilder
            ->symbol('SOLUSDT')
            ->timeframe('5m')
            ->closes([100, 101, 102, 103, 104])
            ->highs([101, 102, 103, 104, 105])
            ->lows([99, 100, 101, 102, 103])
            ->volumes([2000, 2100, 2200, 2300, 2400])
            ->build();
    }
}


