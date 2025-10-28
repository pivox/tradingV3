<?php
declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\TradeEntry\Execution\ExecutionResult;
use Psr\Log\LoggerInterface;
 
final class TradeEntryAlertService
{
    private array $alertThresholds = [
        'error_rate_threshold' => 20.0, // 20% d'erreurs
        'success_rate_threshold' => 30.0, // 30% de succès minimum
        'execution_time_threshold' => 5.0, // 5 secondes max
        'consecutive_failures_threshold' => 5, // 5 échecs consécutifs
    ];

    private int $consecutiveFailures = 0;
    private array $recentExecutions = [];
    private const RECENT_EXECUTIONS_LIMIT = 20;

    public function __construct(
        private LoggerInterface $logger
    ) {}

    public function checkAlerts(
        string $symbol,
        string $side,
        float $executionTime,
        ExecutionResult $result,
        array $metrics
    ): void {
        $this->updateRecentExecutions($result);
        $this->checkPerformanceAlerts($symbol, $side, $executionTime, $result, $metrics);
        $this->checkConsecutiveFailureAlert($result);
        $this->checkExecutionTimeAlert($executionTime, $symbol);
    }

    private function updateRecentExecutions(ExecutionResult $result): void
    {
        $this->recentExecutions[] = [
            'timestamp' => time(),
            'status' => $result->status,
            'success' => $result->status === 'order_opened'
        ];

        // Garder seulement les 20 dernières exécutions
        if (count($this->recentExecutions) > self::RECENT_EXECUTIONS_LIMIT) {
            array_shift($this->recentExecutions);
        }
    }

    private function checkPerformanceAlerts(
        string $symbol,
        string $side,
        float $executionTime,
        ExecutionResult $result,
        array $metrics
    ): void {
        $totalRequests = $metrics['performance']['total_requests'] ?? 0;
        $successRate = $this->extractPercentage($metrics['performance']['success_rate'] ?? '0%');
        $errorRate = $this->extractPercentage($metrics['health']['error_rate'] ?? '0%');

        // Alerte taux d'erreur élevé
        if ($errorRate > $this->alertThresholds['error_rate_threshold']) {
            $this->sendAlert('HIGH_ERROR_RATE', [
                'error_rate' => $errorRate . '%',
                'threshold' => $this->alertThresholds['error_rate_threshold'] . '%',
                'total_requests' => $totalRequests
            ]);
        }

        // Alerte taux de succès faible
        if ($successRate < $this->alertThresholds['success_rate_threshold']) {
            $this->sendAlert('LOW_SUCCESS_RATE', [
                'success_rate' => $successRate . '%',
                'threshold' => $this->alertThresholds['success_rate_threshold'] . '%',
                'total_requests' => $totalRequests
            ]);
        }

        // Alerte échec d'exécution
        if ($result->status === 'cancelled' && $result->data['reason'] === 'execution_error') {
            $this->sendAlert('EXECUTION_ERROR', [
                'symbol' => $symbol,
                'side' => $side,
                'error' => $result->data['error'] ?? 'Unknown error',
                'execution_time' => $executionTime
            ]);
        }

        // Alerte échec de zone d'entrée
        if ($result->status === 'cancelled' && $result->data['reason'] === 'entry_zone_invalid_or_filters_failed') {
            $this->sendAlert('ZONE_FILTER_FAILURE', [
                'symbol' => $symbol,
                'side' => $side,
                'reason' => 'Entry zone invalid or filters failed',
                'execution_time' => $executionTime
            ]);
        }
    }

    private function checkConsecutiveFailureAlert(ExecutionResult $result): void
    {
        if ($result->status !== 'order_opened') {
            $this->consecutiveFailures++;
        } else {
            $this->consecutiveFailures = 0;
        }

        if ($this->consecutiveFailures >= $this->alertThresholds['consecutive_failures_threshold']) {
            $this->sendAlert('CONSECUTIVE_FAILURES', [
                'consecutive_failures' => $this->consecutiveFailures,
                'threshold' => $this->alertThresholds['consecutive_failures_threshold'],
                'last_status' => $result->status,
                'last_reason' => $result->data['reason'] ?? 'N/A'
            ]);
        }
    }

    private function checkExecutionTimeAlert(float $executionTime, string $symbol): void
    {
        if ($executionTime > $this->alertThresholds['execution_time_threshold']) {
            $this->sendAlert('SLOW_EXECUTION', [
                'symbol' => $symbol,
                'execution_time' => $executionTime . 's',
                'threshold' => $this->alertThresholds['execution_time_threshold'] . 's'
            ]);
        }
    }

    private function sendAlert(string $alertType, array $data): void
    {
        $alertData = [
            'alert_type' => $alertType,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $data
        ];

        // Log de l'alerte
        $this->logger->error("TradeEntry Alert: $alertType", $alertData);

        // Ici tu peux ajouter d'autres canaux d'alerte :
        // - Email
        // - Slack
        // - Webhook
        // - Base de données
        // - etc.
    }

    private function extractPercentage(string $percentageStr): float
    {
        return (float) str_replace('%', '', $percentageStr);
    }

    public function getAlertThresholds(): array
    {
        return $this->alertThresholds;
    }

    public function updateAlertThresholds(array $thresholds): void
    {
        $this->alertThresholds = array_merge($this->alertThresholds, $thresholds);
    }

    public function getRecentExecutions(): array
    {
        return $this->recentExecutions;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }
}




