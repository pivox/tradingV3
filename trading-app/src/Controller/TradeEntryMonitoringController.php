<?php
declare(strict_types=1);

namespace App\Controller;

use App\TradeEntry\TradeEntryBox;
use App\TradeEntry\Service\RealMarketTestService;
use App\TradeEntry\Service\TradeEntryBacktestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/trade-entry/monitoring', name: 'trade_entry_monitoring_')]
final class TradeEntryMonitoringController extends AbstractController
{
    public function __construct(
        private TradeEntryBox $tradeEntryBox,
        private RealMarketTestService $realMarketTestService,
        private TradeEntryBacktestService $backtestService
    ) {}

    #[Route('/metrics', name: 'metrics', methods: ['GET'])]
    public function getMetrics(): JsonResponse
    {
        return new JsonResponse($this->tradeEntryBox->getMetrics());
    }

    #[Route('/alerts', name: 'alerts', methods: ['GET'])]
    public function getAlerts(): JsonResponse
    {
        return new JsonResponse($this->tradeEntryBox->getRecentAlerts());
    }

    #[Route('/reset-metrics', name: 'reset_metrics', methods: ['POST'])]
    public function resetMetrics(): JsonResponse
    {
        $this->tradeEntryBox->resetMetrics();
        return new JsonResponse(['message' => 'Metrics reset successfully']);
    }

    #[Route('/real-market-test', name: 'real_market_test', methods: ['GET'])]
    public function realMarketTest(): JsonResponse
    {
        $result = $this->realMarketTestService->testBtcUsdtRealData();
        return new JsonResponse($result);
    }

    #[Route('/backtest', name: 'backtest', methods: ['POST'])]
    public function runBacktest(): JsonResponse
    {
        // Données historiques simulées pour le test
        $historicalData = $this->generateSampleHistoricalData();
        
        $backtestConfig = [
            'initial_capital' => 10000.0,
            'risk_per_trade' => 2.0,
            'max_trades' => 50,
            'symbols' => ['BTCUSDT']
        ];

        $result = $this->backtestService->runBacktest($historicalData, $backtestConfig);
        
        return new JsonResponse($result);
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function healthCheck(): JsonResponse
    {
        $metrics = $this->tradeEntryBox->getMetrics();
        $alerts = $this->tradeEntryBox->getRecentAlerts();
        
        $isHealthy = $metrics['health']['is_healthy'] ?? false;
        $errorRate = $metrics['health']['error_rate'] ?? '0%';
        $successRate = $metrics['performance']['success_rate'] ?? '0%';
        
        return new JsonResponse([
            'status' => $isHealthy ? 'healthy' : 'unhealthy',
            'metrics' => [
                'success_rate' => $successRate,
                'error_rate' => $errorRate,
                'total_requests' => $metrics['performance']['total_requests'] ?? 0
            ],
            'alerts' => [
                'consecutive_failures' => $alerts['consecutive_failures'] ?? 0,
                'recent_executions_count' => count($alerts['recent_executions'] ?? [])
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    private function generateSampleHistoricalData(): array
    {
        $data = [];
        $basePrice = 50000.0;
        $timestamp = time() - (100 * 60); // 100 minutes ago
        
        for ($i = 0; $i < 100; $i++) {
            $price = $basePrice + (rand(-1000, 1000) / 100);
            $volume = rand(100, 1000);
            
            $data[] = [
                'timestamp' => $timestamp + ($i * 60),
                'open' => $price,
                'high' => $price + rand(0, 50),
                'low' => $price - rand(0, 50),
                'close' => $price + rand(-25, 25),
                'volume' => $volume,
                'symbol' => 'BTCUSDT'
            ];
        }
        
        return $data;
    }
}





