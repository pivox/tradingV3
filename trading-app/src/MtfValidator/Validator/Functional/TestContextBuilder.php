<?php

declare(strict_types=1);

namespace App\MtfValidator\Validator\Functional;

use App\Indicator\Context\IndicatorContextBuilder;

/**
 * Générateur de contextes de test réalistes pour la validation fonctionnelle
 */
final class TestContextBuilder
{
    public function __construct(
        private readonly IndicatorContextBuilder $contextBuilder
    ) {
    }

    /**
     * Crée un contexte pour une tendance haussière (long)
     */
    public function buildBullishContext(string $symbol = 'BTCUSDT', string $timeframe = '15m'): array
    {
        $basePrice = 50000.0;
        $n = 220; // Suffisamment de données pour les indicateurs
        
        $closes = [];
        $highs = [];
        $lows = [];
        $volumes = [];
        
        // Tendance haussière avec volatilité réaliste
        for ($i = 0; $i < $n; $i++) {
            $trend = $i * 2.0; // Tendance haussière
            $volatility = sin($i / 10.0) * 50.0; // Volatilité cyclique
            $close = $basePrice + $trend + $volatility;
            
            $closes[] = $close;
            $highs[] = $close + 100.0 + rand(0, 50);
            $lows[] = $close - 100.0 - rand(0, 50);
            $volumes[] = 1000.0 + ($i * 10) + rand(0, 200);
        }
        
        return $this->contextBuilder
            ->symbol($symbol)
            ->timeframe($timeframe)
            ->closes($closes)
            ->highs($highs)
            ->lows($lows)
            ->volumes($volumes)
            ->withDefaults()
            ->build();
    }

    /**
     * Crée un contexte pour une tendance baissière (short)
     */
    public function buildBearishContext(string $symbol = 'BTCUSDT', string $timeframe = '15m'): array
    {
        $basePrice = 50000.0;
        $n = 220;
        
        $closes = [];
        $highs = [];
        $lows = [];
        $volumes = [];
        
        // Tendance baissière avec volatilité réaliste
        for ($i = 0; $i < $n; $i++) {
            $trend = -$i * 2.0; // Tendance baissière
            $volatility = sin($i / 10.0) * 50.0;
            $close = $basePrice + $trend + $volatility;
            
            $closes[] = $close;
            $highs[] = $close + 100.0 + rand(0, 50);
            $lows[] = $close - 100.0 - rand(0, 50);
            $volumes[] = 1000.0 + ($i * 10) + rand(0, 200);
        }
        
        return $this->contextBuilder
            ->symbol($symbol)
            ->timeframe($timeframe)
            ->closes($closes)
            ->highs($highs)
            ->lows($lows)
            ->volumes($volumes)
            ->withDefaults()
            ->build();
    }

    /**
     * Crée un contexte pour un marché latéral (sideways)
     */
    public function buildSidewaysContext(string $symbol = 'BTCUSDT', string $timeframe = '15m'): array
    {
        $basePrice = 50000.0;
        $n = 220;
        
        $closes = [];
        $highs = [];
        $lows = [];
        $volumes = [];
        
        // Marché latéral avec oscillation autour d'un prix moyen
        for ($i = 0; $i < $n; $i++) {
            $oscillation = sin($i / 5.0) * 200.0; // Oscillation
            $noise = (rand(-50, 50) / 1.0);
            $close = $basePrice + $oscillation + $noise;
            
            $closes[] = $close;
            $highs[] = $close + 80.0 + rand(0, 40);
            $lows[] = $close - 80.0 - rand(0, 40);
            $volumes[] = 1000.0 + rand(0, 300);
        }
        
        return $this->contextBuilder
            ->symbol($symbol)
            ->timeframe($timeframe)
            ->closes($closes)
            ->highs($highs)
            ->lows($lows)
            ->volumes($volumes)
            ->withDefaults()
            ->build();
    }

    /**
     * Crée un contexte avec RSI élevé (>70) pour tester les filtres
     */
    public function buildHighRsiContext(string $symbol = 'BTCUSDT', string $timeframe = '15m'): array
    {
        $basePrice = 50000.0;
        $n = 220;
        
        $closes = [];
        $highs = [];
        $lows = [];
        $volumes = [];
        
        // Tendance haussière très forte (RSI élevé)
        for ($i = 0; $i < $n; $i++) {
            $trend = $i * 5.0; // Tendance très forte
            $close = $basePrice + $trend;
            
            $closes[] = $close;
            $highs[] = $close + 50.0;
            $lows[] = $close - 20.0; // Faibles corrections
            $volumes[] = 1000.0 + ($i * 10);
        }
        
        return $this->contextBuilder
            ->symbol($symbol)
            ->timeframe($timeframe)
            ->closes($closes)
            ->highs($highs)
            ->lows($lows)
            ->volumes($volumes)
            ->withDefaults()
            ->build();
    }

    /**
     * Crée un contexte avec ATR élevé pour tester les conditions ATR
     */
    public function buildHighAtrContext(string $symbol = 'BTCUSDT', string $timeframe = '15m'): array
    {
        $basePrice = 50000.0;
        $n = 220;
        
        $closes = [];
        $highs = [];
        $lows = [];
        $volumes = [];
        
        // Volatilité élevée (ATR élevé)
        for ($i = 0; $i < $n; $i++) {
            $volatility = (rand(-500, 500) / 1.0); // Grande volatilité
            $close = $basePrice + $volatility;
            
            $closes[] = $close;
            $highs[] = $close + 300.0 + rand(0, 200);
            $lows[] = $close - 300.0 - rand(0, 200);
            $volumes[] = 2000.0 + rand(0, 1000);
        }
        
        return $this->contextBuilder
            ->symbol($symbol)
            ->timeframe($timeframe)
            ->closes($closes)
            ->highs($highs)
            ->lows($lows)
            ->volumes($volumes)
            ->withDefaults()
            ->build();
    }

    /**
     * Crée un contexte personnalisé avec des paramètres spécifiques
     */
    public function buildCustomContext(
        string $symbol,
        string $timeframe,
        array $closes,
        array $highs = [],
        array $lows = [],
        array $volumes = [],
        array $additionalData = []
    ): array {
        $context = $this->contextBuilder
            ->symbol($symbol)
            ->timeframe($timeframe)
            ->closes($closes)
            ->withDefaults();
        
        if (!empty($highs)) {
            $context->highs($highs);
        }
        if (!empty($lows)) {
            $context->lows($lows);
        }
        if (!empty($volumes)) {
            $context->volumes($volumes);
        }
        
        $built = $context->build();
        
        // Fusionner les données additionnelles
        return array_merge($built, $additionalData);
    }

    /**
     * Enrichit un contexte avec des données pour l'execution selector
     */
    public function enrichForExecutionSelector(array $context, array $selectorData = []): array
    {
        $defaults = [
            'expected_r_multiple' => 2.0,
            'entry_zone_width_pct' => 1.0,
            'atr_pct_15m_bps' => 100.0,
            'adx_5m' => 25.0,
            'spread_bps' => 5.0,
            'leverage' => 10.0,
            'scalping' => false,
            'trailing_after_tp1' => false,
            'end_of_zone_fallback' => false,
        ];
        
        return array_merge($context, $defaults, $selectorData);
    }
}






