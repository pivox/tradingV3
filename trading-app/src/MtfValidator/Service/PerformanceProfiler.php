<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

/**
 * Collecteur de métriques de performance pour l'analyse MTF
 */
final class PerformanceProfiler
{
    private array $metrics = [];
    private float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function start(string $category, string $operation, ?string $symbol = null, ?string $timeframe = null): string
    {
        $key = $this->buildKey($category, $operation, $symbol, $timeframe);
        $this->metrics[$key] = [
            'category' => $category,
            'operation' => $operation,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'start' => microtime(true),
            'end' => null,
            'duration' => null,
            'count' => 1,
        ];
        return $key;
    }

    public function end(string $key, ?array $extra = null): void
    {
        if (!isset($this->metrics[$key])) {
            return;
        }
        $endTime = microtime(true);
        $this->metrics[$key]['end'] = $endTime;
        $this->metrics[$key]['duration'] = $endTime - $this->metrics[$key]['start'];
        if ($extra !== null) {
            $this->metrics[$key] = array_merge($this->metrics[$key], $extra);
        }
    }

    public function increment(string $category, string $operation, float $duration, ?string $symbol = null, ?string $timeframe = null, ?array $extra = null): void
    {
        $key = $this->buildKey($category, $operation, $symbol, $timeframe);
        if (!isset($this->metrics[$key])) {
            $this->metrics[$key] = [
                'category' => $category,
                'operation' => $operation,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'duration' => 0,
                'count' => 0,
            ];
        }
        $this->metrics[$key]['duration'] += $duration;
        $this->metrics[$key]['count']++;
        if ($extra !== null) {
            foreach ($extra as $k => $v) {
                if (!isset($this->metrics[$key][$k])) {
                    $this->metrics[$key][$k] = $v;
                } else {
                    if (is_numeric($v)) {
                        $this->metrics[$key][$k] = ($this->metrics[$key][$k] ?? 0) + $v;
                    }
                }
            }
        }
    }

    public function getReport(): array
    {
        $totalTime = microtime(true) - $this->startTime;
        $byCategory = [];
        $byOperation = [];
        $symbolStats = [];
        $timeframeStats = [];

        foreach ($this->metrics as $key => $metric) {
            $category = $metric['category'];
            $operation = $metric['operation'];
            $duration = $metric['duration'] ?? 0;
            $count = $metric['count'] ?? 1;

            // Par catégorie
            if (!isset($byCategory[$category])) {
                $byCategory[$category] = ['total' => 0, 'count' => 0, 'avg' => 0];
            }
            $byCategory[$category]['total'] += $duration;
            $byCategory[$category]['count'] += $count;

            // Par opération
            $opKey = $category . '::' . $operation;
            if (!isset($byOperation[$opKey])) {
                $byOperation[$opKey] = ['total' => 0, 'count' => 0, 'avg' => 0, 'min' => PHP_FLOAT_MAX, 'max' => 0];
            }
            $byOperation[$opKey]['total'] += $duration;
            $byOperation[$opKey]['count'] += $count;
            if ($duration < $byOperation[$opKey]['min']) {
                $byOperation[$opKey]['min'] = $duration;
            }
            if ($duration > $byOperation[$opKey]['max']) {
                $byOperation[$opKey]['max'] = $duration;
            }

            // Par symbole
            if ($metric['symbol'] !== null) {
                $symbol = $metric['symbol'];
                if (!isset($symbolStats[$symbol])) {
                    $symbolStats[$symbol] = ['total' => 0, 'count' => 0];
                }
                $symbolStats[$symbol]['total'] += $duration;
                $symbolStats[$symbol]['count'] += $count;
            }

            // Par timeframe
            if ($metric['timeframe'] !== null) {
                $tf = $metric['timeframe'];
                if (!isset($timeframeStats[$tf])) {
                    $timeframeStats[$tf] = ['total' => 0, 'count' => 0];
                }
                $timeframeStats[$tf]['total'] += $duration;
                $timeframeStats[$tf]['count'] += $count;
            }
        }

        // Calculer les moyennes
        foreach ($byCategory as &$cat) {
            $cat['avg'] = $cat['count'] > 0 ? $cat['total'] / $cat['count'] : 0;
        }
        foreach ($byOperation as &$op) {
            $op['avg'] = $op['count'] > 0 ? $op['total'] / $op['count'] : 0;
        }
        foreach ($symbolStats as &$stat) {
            $stat['avg'] = $stat['count'] > 0 ? $stat['total'] / $stat['count'] : 0;
        }
        foreach ($timeframeStats as &$stat) {
            $stat['avg'] = $stat['count'] > 0 ? $stat['total'] / $stat['count'] : 0;
        }

        // Trier les symboles par temps total
        uasort($symbolStats, fn($a, $b) => $b['total'] <=> $a['total']);

        return [
            'total_execution_time' => round($totalTime, 3),
            'by_category' => $byCategory,
            'by_operation' => $byOperation,
            'by_symbol' => array_slice($symbolStats, 0, 20), // Top 20
            'by_timeframe' => $timeframeStats,
            'raw_metrics' => $this->metrics,
        ];
    }

    private function buildKey(string $category, string $operation, ?string $symbol = null, ?string $timeframe = null): string
    {
        $parts = [$category, $operation];
        if ($symbol !== null) {
            $parts[] = $symbol;
        }
        if ($timeframe !== null) {
            $parts[] = $timeframe;
        }
        return implode('::', $parts);
    }
}

