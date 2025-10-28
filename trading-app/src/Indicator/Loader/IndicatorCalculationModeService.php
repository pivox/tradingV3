<?php

declare(strict_types=1);

namespace App\Indicator\Loader;

use App\Service\TradingConfigService;
use Psr\Log\LoggerInterface;

/**
 * Service pour gérer le mode de calcul des indicateurs (PHP vs SQL)
 */
class IndicatorCalculationModeService
{
    public const MODE_PHP = 'php';
    public const MODE_SQL = 'sql';

    private string $currentMode;
    private bool $fallbackEnabled;
    private int $performanceThresholdMs;
    private array $performanceMetrics = [];

    public function __construct(
        private readonly TradingConfigService $configService,
        private readonly ?LoggerInterface $logger = null
    ) {
        $config = $this->configService->getConfig();
        $indicatorConfig = $config['indicator_calculation'] ?? [];

        $this->currentMode = $indicatorConfig['mode'] ?? self::MODE_SQL;
        $this->fallbackEnabled = $indicatorConfig['fallback_to_php'] ?? true;
        $this->performanceThresholdMs = $indicatorConfig['performance_threshold_ms'] ?? 100;
    }

    /**
     * Retourne le mode de calcul actuel
     */
    public function getCurrentMode(): string
    {
        return $this->currentMode;
    }

    /**
     * Retourne le mode de calcul actuel (alias)
     */
    public function getMode(): string
    {
        return $this->currentMode;
    }

    /**
     * Vérifie si le mode actuel est SQL
     */
    public function isSqlMode(): bool
    {
        return $this->currentMode === self::MODE_SQL;
    }

    /**
     * Vérifie si le mode actuel est PHP
     */
    public function isPhpMode(): bool
    {
        return $this->currentMode === self::MODE_PHP;
    }

    /**
     * Retourne le seuil de performance en millisecondes
     */
    public function getPerformanceThreshold(): int
    {
        return $this->performanceThresholdMs;
    }

    /**
     * Définit le mode de calcul
     */
    public function setMode(string $mode): void
    {
        if (!in_array($mode, [self::MODE_PHP, self::MODE_SQL])) {
            throw new \InvalidArgumentException("Mode invalide: $mode. Utilisez 'php' ou 'sql'.");
        }

        $this->currentMode = $mode;
        $this->logger?->info('Mode de calcul des indicateurs changé', ['mode' => $mode]);
    }

    /**
     * Détermine le mode de calcul à utiliser pour un indicateur donné
     */
    public function getCalculationMode(string $indicatorName, string $symbol, string $timeframe): string
    {
        // Vérifier si on doit utiliser SQL
        if ($this->currentMode === self::MODE_SQL) {
            // Vérifier les performances récentes
            $key = "$indicatorName:$symbol:$timeframe";
            if (isset($this->performanceMetrics[$key])) {
                $avgTime = $this->performanceMetrics[$key]['avg_time_ms'];
                if ($avgTime > $this->performanceThresholdMs) {
                    $this->logger?->warning('Performance SQL dégradée, switch vers PHP', [
                        'indicator' => $indicatorName,
                        'symbol' => $symbol,
                        'timeframe' => $timeframe,
                        'avg_time_ms' => $avgTime,
                        'threshold_ms' => $this->performanceThresholdMs
                    ]);
                    return self::MODE_PHP;
                }
            }
            return self::MODE_SQL;
        }

        return self::MODE_PHP;
    }

    /**
     * Enregistre les métriques de performance pour un calcul
     */
    public function recordPerformance(string $indicatorName, string $symbol, string $timeframe, int $executionTimeMs, bool $success): void
    {
        $key = "$indicatorName:$symbol:$timeframe";

        if (!isset($this->performanceMetrics[$key])) {
            $this->performanceMetrics[$key] = [
                'count' => 0,
                'total_time_ms' => 0,
                'avg_time_ms' => 0,
                'success_count' => 0,
                'failure_count' => 0
            ];
        }

        $metrics = &$this->performanceMetrics[$key];
        $metrics['count']++;
        $metrics['total_time_ms'] += $executionTimeMs;
        $metrics['avg_time_ms'] = $metrics['total_time_ms'] / $metrics['count'];

        if ($success) {
            $metrics['success_count']++;
        } else {
            $metrics['failure_count']++;
        }

        // Garder seulement les 100 dernières entrées pour éviter la fuite mémoire
        if (count($this->performanceMetrics) > 100) {
            $this->performanceMetrics = array_slice($this->performanceMetrics, -100, null, true);
        }
    }

    /**
     * Vérifie si le fallback est activé
     */
    public function isFallbackEnabled(): bool
    {
        return $this->fallbackEnabled;
    }

    /**
     * Force le fallback vers PHP pour un indicateur
     */
    public function forceFallbackToPhp(string $indicatorName, string $symbol, string $timeframe, string $reason): void
    {
        $this->logger?->warning('Fallback forcé vers PHP', [
            'indicator' => $indicatorName,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'reason' => $reason
        ]);
    }

    /**
     * Retourne les métriques de performance
     */
    public function getPerformanceMetrics(): array
    {
        return $this->performanceMetrics;
    }

    /**
     * Réinitialise les métriques de performance
     */
    public function resetPerformanceMetrics(): void
    {
        $this->performanceMetrics = [];
        $this->logger?->info('Métriques de performance réinitialisées');
    }

    /**
     * Retourne un résumé des performances
     */
    public function getPerformanceSummary(): array
    {
        $summary = [
            'total_indicators' => count($this->performanceMetrics),
            'avg_performance_ms' => 0,
            'slowest_indicator' => null,
            'fastest_indicator' => null,
            'success_rate' => 0
        ];

        if (empty($this->performanceMetrics)) {
            return $summary;
        }

        $totalTime = 0;
        $totalCount = 0;
        $totalSuccess = 0;
        $totalAttempts = 0;
        $slowestTime = 0;
        $fastestTime = PHP_INT_MAX;

        foreach ($this->performanceMetrics as $key => $metrics) {
            $totalTime += $metrics['total_time_ms'];
            $totalCount += $metrics['count'];
            $totalSuccess += $metrics['success_count'];
            $totalAttempts += $metrics['count'];

            if ($metrics['avg_time_ms'] > $slowestTime) {
                $slowestTime = $metrics['avg_time_ms'];
                $summary['slowest_indicator'] = $key;
            }

            if ($metrics['avg_time_ms'] < $fastestTime) {
                $fastestTime = $metrics['avg_time_ms'];
                $summary['fastest_indicator'] = $key;
            }
        }

        $summary['avg_performance_ms'] = $totalCount > 0 ? $totalTime / $totalCount : 0;
        $summary['success_rate'] = $totalAttempts > 0 ? ($totalSuccess / $totalAttempts) * 100 : 0;

        return $summary;
    }
}
