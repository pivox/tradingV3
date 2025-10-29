<?php
declare(strict_types=1);

namespace App\Controller;

use App\TradeEntry\Service\TradeEntryMetricsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/trade-entry/monitoring', name: 'trade_entry_monitoring_')]
final class TradeEntryMonitoringController extends AbstractController
{
    public function __construct(private readonly TradeEntryMetricsService $metrics) {}

    #[Route('/metrics', name: 'metrics', methods: ['GET'])]
    public function getMetrics(): JsonResponse
    {
        return new JsonResponse($this->metrics->snapshot());
    }

    #[Route('/alerts', name: 'alerts', methods: ['GET'])]
    public function getAlerts(): JsonResponse
    {
        return new JsonResponse(['message' => 'Alerts not implemented'], 501);
    }

    #[Route('/reset-metrics', name: 'reset_metrics', methods: ['POST'])]
    public function resetMetrics(): JsonResponse
    {
        $this->metrics->reset();
        return new JsonResponse(['message' => 'Metrics reset successfully']);
    }

    #[Route('/real-market-test', name: 'real_market_test', methods: ['GET'])]
    public function realMarketTest(): JsonResponse
    {
        return new JsonResponse(['message' => 'Not implemented'], 501);
    }

    #[Route('/backtest', name: 'backtest', methods: ['POST'])]
    public function runBacktest(): JsonResponse
    {
        return new JsonResponse(['message' => 'Not implemented'], 501);
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function healthCheck(): JsonResponse
    {
        $metrics = $this->metrics->snapshot();
        $total = array_sum($metrics);
        $errors = $metrics['errors'] ?? 0;
        $successRate = $total > 0 ? round((($total - $errors) / $total) * 100, 2) : 0.0;
        $errorRate = $total > 0 ? round(($errors / $total) * 100, 2) : 0.0;
        
        return new JsonResponse([
            'status' => $errorRate < 50.0 ? 'healthy' : 'unhealthy',
            'metrics' => $metrics,
            'success_rate' => $successRate,
            'error_rate' => $errorRate,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}






