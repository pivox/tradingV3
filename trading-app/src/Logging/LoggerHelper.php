<?php

namespace App\Logging;

use Monolog\Logger;

/**
 * Helper pour faciliter l'utilisation des canaux de logging métier
 */
class LoggerHelper
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Log pour la validation des règles MTF et conditions YAML
     */
    public function validation(string $message, array $context = []): void
    {
        $this->logger->withName('validation')->info($message, $context);
    }

    /**
     * Log pour les signaux de trading (long/short)
     */
    public function signal(string $message, array $context = []): void
    {
        $this->logger->withName('signals')->info($message, $context);
    }

    /**
     * Log pour le suivi des positions
     */
    public function position(string $message, array $context = []): void
    {
        $this->logger->withName('positions')->info($message, $context);
    }

    /**
     * Log pour les calculs d'indicateurs techniques
     */
    public function indicator(string $message, array $context = []): void
    {
        $this->logger->withName('indicators')->info($message, $context);
    }

    /**
     * Log pour les stratégies High Conviction
     */
    public function highConviction(string $message, array $context = []): void
    {
        $this->logger->withName('highconviction')->info($message, $context);
    }

    /**
     * Log pour l'exécution du pipeline
     */
    public function pipelineExec(string $message, array $context = []): void
    {
        $this->logger->withName('pipeline_exec')->info($message, $context);
    }

    /**
     * Log pour les erreurs globales (severity ≥ error)
     */
    public function globalSeverity(string $message, array $context = []): void
    {
        $this->logger->withName('global-severity')->error($message, $context);
    }

    /**
     * Log structuré avec métadonnées de trading
     */
    public function tradingLog(
        string $channel,
        string $message,
        string $symbol = '',
        string $timeframe = '',
        string $side = '',
        array $context = []
    ): void {
        $enrichedContext = array_merge($context, [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'side' => $side,
        ]);

        $this->logger->withName($channel)->info($message, $enrichedContext);
    }

    /**
     * Log de signal avec format standardisé
     */
    public function logSignal(
        string $symbol,
        string $timeframe,
        string $side,
        string $message,
        array $indicators = []
    ): void {
        $this->tradingLog('signals', $message, $symbol, $timeframe, $side, [
            'indicators' => $indicators,
            'timestamp' => time(),
        ]);
    }

    /**
     * Log de position avec format standardisé
     */
    public function logPosition(
        string $symbol,
        string $action, // 'open', 'close', 'sl_hit', 'tp_hit'
        string $side,
        array $positionData = []
    ): void {
        $this->tradingLog('positions', "Position {$action}", $symbol, '', $side, [
            'action' => $action,
            'position_data' => $positionData,
            'timestamp' => time(),
        ]);
    }

    /**
     * Log d'indicateur avec format standardisé
     */
    public function logIndicator(
        string $symbol,
        string $timeframe,
        string $indicatorName,
        array $values,
        string $message = ''
    ): void {
        $this->tradingLog('indicators', $message ?: "Indicator {$indicatorName} calculated", $symbol, $timeframe, '', [
            'indicator' => $indicatorName,
            'values' => $values,
            'timestamp' => time(),
        ]);
    }
}
