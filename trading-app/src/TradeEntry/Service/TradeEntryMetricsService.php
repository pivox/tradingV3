<?php
declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\TradeEntry\Execution\ExecutionResult;
use Psr\Log\LoggerInterface;
 
final class TradeEntryMetricsService
{
    private array $metrics = [
        'total_requests' => 0,
        'successful_orders' => 0,
        'cancelled_orders' => 0,
        'execution_errors' => 0,
        'zone_filter_failures' => 0,
        'average_execution_time' => 0.0,
        'total_execution_time' => 0.0,
        'leverage_distribution' => [],
        'symbol_distribution' => [],
        'side_distribution' => ['long' => 0, 'short' => 0],
        'risk_distribution' => [],
    ];

    public function __construct(
        private LoggerInterface $logger
    ) {}

    public function recordExecution(
        string $symbol,
        string $side,
        float $executionTime,
        ExecutionResult $result,
        array $input
    ): void {
        $this->metrics['total_requests']++;
        $this->metrics['total_execution_time'] += $executionTime;
        $this->metrics['average_execution_time'] = 
            $this->metrics['total_execution_time'] / $this->metrics['total_requests'];

        // Distribution par symbole
        $this->metrics['symbol_distribution'][$symbol] = 
            ($this->metrics['symbol_distribution'][$symbol] ?? 0) + 1;

        // Distribution par side
        $this->metrics['side_distribution'][$side]++;

        // Distribution par levier (si disponible)
        if (isset($input['risk_pct'])) {
            $riskRange = $this->getRiskRange($input['risk_pct']);
            $this->metrics['risk_distribution'][$riskRange] = 
                ($this->metrics['risk_distribution'][$riskRange] ?? 0) + 1;
        }

        // Compteurs de résultats
        switch ($result->status) {
            case 'order_opened':
                $this->metrics['successful_orders']++;
                $this->logger->info('TradeEntry: Order executed successfully', [
                    'symbol' => $symbol,
                    'side' => $side,
                    'execution_time' => $executionTime,
                    'order_id' => $result->data['order_id'] ?? null
                ]);
                break;
                
            case 'cancelled':
                $this->metrics['cancelled_orders']++;
                $reason = $result->data['reason'] ?? 'unknown';
                
                if ($reason === 'entry_zone_invalid_or_filters_failed') {
                    $this->metrics['zone_filter_failures']++;
                }
                
                $this->logger->warning('TradeEntry: Order cancelled', [
                    'symbol' => $symbol,
                    'side' => $side,
                    'reason' => $reason,
                    'execution_time' => $executionTime
                ]);
                break;
                
            default:
                $this->metrics['execution_errors']++;
                $this->logger->error('TradeEntry: Execution error', [
                    'symbol' => $symbol,
                    'side' => $side,
                    'status' => $result->status,
                    'execution_time' => $executionTime
                ]);
        }

        // Log des métriques toutes les 10 exécutions
        if ($this->metrics['total_requests'] % 10 === 0) {
            $this->logMetrics();
        }
    }

    public function getMetrics(): array
    {
        $successRate = $this->metrics['total_requests'] > 0 
            ? ($this->metrics['successful_orders'] / $this->metrics['total_requests']) * 100 
            : 0;

        return [
            'performance' => [
                'total_requests' => $this->metrics['total_requests'],
                'success_rate' => round($successRate, 2) . '%',
                'successful_orders' => $this->metrics['successful_orders'],
                'cancelled_orders' => $this->metrics['cancelled_orders'],
                'execution_errors' => $this->metrics['execution_errors'],
                'zone_filter_failures' => $this->metrics['zone_filter_failures'],
                'average_execution_time' => round($this->metrics['average_execution_time'], 3) . 's'
            ],
            'distributions' => [
                'symbols' => $this->metrics['symbol_distribution'],
                'sides' => $this->metrics['side_distribution'],
                'risk_levels' => $this->metrics['risk_distribution']
            ],
            'health' => [
                'is_healthy' => $successRate > 50 && $this->metrics['execution_errors'] < 5,
                'error_rate' => $this->metrics['total_requests'] > 0 
                    ? round(($this->metrics['execution_errors'] / $this->metrics['total_requests']) * 100, 2) . '%'
                    : '0%'
            ]
        ];
    }

    public function resetMetrics(): void
    {
        $this->metrics = [
            'total_requests' => 0,
            'successful_orders' => 0,
            'cancelled_orders' => 0,
            'execution_errors' => 0,
            'zone_filter_failures' => 0,
            'average_execution_time' => 0.0,
            'total_execution_time' => 0.0,
            'leverage_distribution' => [],
            'symbol_distribution' => [],
            'side_distribution' => ['long' => 0, 'short' => 0],
            'risk_distribution' => [],
        ];
        
        $this->logger->info('TradeEntry: Metrics reset');
    }

    private function getRiskRange(float $riskPct): string
    {
        if ($riskPct < 1.0) return '0-1%';
        if ($riskPct < 2.0) return '1-2%';
        if ($riskPct < 3.0) return '2-3%';
        if ($riskPct < 5.0) return '3-5%';
        return '5%+';
    }

    private function logMetrics(): void
    {
        $metrics = $this->getMetrics();
        
        $this->logger->info('TradeEntry: Performance metrics', [
            'performance' => $metrics['performance'],
            'health' => $metrics['health']
        ]);
    }
}


