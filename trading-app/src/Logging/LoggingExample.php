<?php

namespace App\Logging;

use App\Logging\LoggerHelper;

/**
 * Exemple d'utilisation du système de logging multi-canaux
 * 
 * Ce fichier démontre comment utiliser les différents canaux de logging
 * selon les spécifications du cahier de charges.
 */
class LoggingExample
{
    private LoggerHelper $logger;

    public function __construct(LoggerHelper $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Exemple de logging pour la validation des règles MTF
     */
    public function logValidationExample(): void
    {
        // Validation d'une règle MTF
        $this->logger->validation('MTF rule validation started', [
            'rule_id' => 'mtf_001',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'conditions' => [
                'macd_bullish' => true,
                'rsi_oversold' => false,
                'volume_spike' => true
            ]
        ]);

        // Validation d'une condition YAML
        $this->logger->validation('YAML condition parsed successfully', [
            'file' => 'highconviction_macro.yaml',
            'section' => 'btc_conditions',
            'parsed_rules' => 5
        ]);
    }

    /**
     * Exemple de logging pour les signaux de trading
     */
    public function logSignalsExample(): void
    {
        // Signal long détecté
        $this->logger->logSignal(
            'BTCUSDT',
            '15m',
            'long',
            'MACD bullish crossover confirmed',
            [
                'macd' => ['hist' => 0.0009, 'signal' => 0.0005],
                'rsi' => 54.2,
                'volume' => 1250000
            ]
        );

        // Signal short détecté
        $this->logger->logSignal(
            'ETHUSDT',
            '1h',
            'short',
            'RSI overbought with bearish divergence',
            [
                'rsi' => 78.5,
                'divergence' => 'bearish',
                'support_level' => 1850.0
            ]
        );
    }

    /**
     * Exemple de logging pour les positions
     */
    public function logPositionsExample(): void
    {
        // Ouverture de position
        $this->logger->logPosition(
            'BTCUSDT',
            'open',
            'long',
            [
                'entry_price' => 43250.50,
                'quantity' => 0.1,
                'stop_loss' => 42500.0,
                'take_profit' => 44500.0,
                'leverage' => 10
            ]
        );

        // Fermeture de position (TP hit)
        $this->logger->logPosition(
            'BTCUSDT',
            'tp_hit',
            'long',
            [
                'exit_price' => 44480.25,
                'pnl' => 122.98,
                'pnl_percentage' => 2.84,
                'duration_minutes' => 45
            ]
        );

        // Stop loss hit
        $this->logger->logPosition(
            'ETHUSDT',
            'sl_hit',
            'short',
            [
                'exit_price' => 1920.75,
                'pnl' => -45.20,
                'pnl_percentage' => -2.35,
                'duration_minutes' => 23
            ]
        );
    }

    /**
     * Exemple de logging pour les indicateurs techniques
     */
    public function logIndicatorsExample(): void
    {
        // Calcul MACD
        $this->logger->logIndicator(
            'BTCUSDT',
            '15m',
            'MACD',
            [
                'macd_line' => 0.0009,
                'signal_line' => 0.0005,
                'histogram' => 0.0004,
                'fast_ema' => 12,
                'slow_ema' => 26,
                'signal_period' => 9
            ],
            'MACD calculated with bullish crossover'
        );

        // Calcul RSI
        $this->logger->logIndicator(
            'ETHUSDT',
            '1h',
            'RSI',
            [
                'rsi' => 54.2,
                'period' => 14,
                'overbought' => false,
                'oversold' => false,
                'divergence' => 'none'
            ],
            'RSI calculated - neutral zone'
        );

        // Calcul EMA
        $this->logger->logIndicator(
            'ADAUSDT',
            '5m',
            'EMA',
            [
                'ema_9' => 0.4521,
                'ema_21' => 0.4489,
                'ema_50' => 0.4456,
                'trend' => 'bullish',
                'crossover' => 'ema_9_above_ema_21'
            ],
            'EMA system showing bullish trend'
        );
    }

    /**
     * Exemple de logging pour les stratégies High Conviction
     */
    public function logHighConvictionExample(): void
    {
        // Stratégie validée
        $this->logger->highConviction('High Conviction strategy activated', [
            'strategy_id' => 'hc_btc_momentum',
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'confidence_score' => 0.87,
            'risk_level' => 'medium',
            'expected_duration' => '2-4 hours',
            'indicators_aligned' => [
                'macd' => 'bullish',
                'rsi' => 'momentum',
                'volume' => 'increasing',
                'ema_trend' => 'bullish'
            ]
        ]);

        // Exécution de la stratégie
        $this->logger->highConviction('Strategy execution completed', [
            'strategy_id' => 'hc_btc_momentum',
            'execution_time' => 180, // secondes
            'positions_opened' => 1,
            'total_pnl' => 245.67,
            'success_rate' => 1.0
        ]);
    }

    /**
     * Exemple de logging pour l'exécution du pipeline
     */
    public function logPipelineExecExample(): void
    {
        // Démarrage du pipeline
        $this->logger->pipelineExec('Pipeline execution started', [
            'pipeline_id' => 'mtf_pipeline_001',
            'symbols' => ['BTCUSDT', 'ETHUSDT', 'ADAUSDT'],
            'timeframes' => ['5m', '15m', '1h'],
            'total_workflows' => 9
        ]);

        // Étape du pipeline
        $this->logger->pipelineExec('Pipeline step completed', [
            'pipeline_id' => 'mtf_pipeline_001',
            'step' => 'indicator_calculation',
            'symbol' => 'BTCUSDT',
            'timeframe' => '15m',
            'duration_ms' => 1250,
            'indicators_calculated' => 5
        ]);

        // Erreur dans le pipeline
        $this->logger->pipelineExec('Pipeline step failed', [
            'pipeline_id' => 'mtf_pipeline_001',
            'step' => 'signal_generation',
            'symbol' => 'ETHUSDT',
            'timeframe' => '1h',
            'error' => 'Insufficient data for RSI calculation',
            'retry_count' => 2
        ]);
    }

    /**
     * Exemple de logging pour les erreurs globales
     */
    public function logGlobalSeverityExample(): void
    {
        // Erreur critique
        $this->logger->globalSeverity('Critical system error detected', [
            'error_type' => 'database_connection',
            'component' => 'trading_engine',
            'severity' => 'critical',
            'impact' => 'trading_halted',
            'recovery_action' => 'restart_service',
            'timestamp' => time()
        ]);

        // Erreur de validation
        $this->logger->globalSeverity('Validation error in MTF system', [
            'error_type' => 'validation_failure',
            'component' => 'mtf_validator',
            'severity' => 'error',
            'rule_id' => 'mtf_rule_005',
            'validation_error' => 'Invalid timeframe configuration'
        ]);
    }

    /**
     * Exécute tous les exemples de logging
     */
    public function runAllExamples(): void
    {
        $this->logValidationExample();
        $this->logSignalsExample();
        $this->logPositionsExample();
        $this->logIndicatorsExample();
        $this->logHighConvictionExample();
        $this->logPipelineExecExample();
        $this->logGlobalSeverityExample();
    }
}
