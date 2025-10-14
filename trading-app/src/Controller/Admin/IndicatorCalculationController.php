<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Indicator\IndicatorCalculationModeService;
use App\Service\Indicator\SqlIndicatorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Contrôleur pour gérer le mode de calcul des indicateurs
 */
#[Route('/admin/indicators', name: 'admin_indicators_')]
class IndicatorCalculationController extends AbstractController
{
    public function __construct(
        private readonly IndicatorCalculationModeService $modeService,
        private readonly SqlIndicatorService $sqlService
    ) {
    }

    /**
     * Affiche la page de gestion des modes de calcul
     */
    #[Route('/calculation-mode', name: 'calculation_mode', methods: ['GET'])]
    public function calculationMode(): Response
    {
        $currentMode = $this->modeService->getCurrentMode();
        $performanceMetrics = $this->modeService->getPerformanceMetrics();
        $performanceSummary = $this->modeService->getPerformanceSummary();

        return $this->render('admin/indicators/calculation_mode.html.twig', [
            'current_mode' => $currentMode,
            'performance_metrics' => $performanceMetrics,
            'performance_summary' => $performanceSummary,
            'fallback_enabled' => $this->modeService->isFallbackEnabled()
        ]);
    }

    /**
     * Change le mode de calcul
     */
    #[Route('/calculation-mode/switch', name: 'switch_calculation_mode', methods: ['POST'])]
    public function switchCalculationMode(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $mode = $data['mode'] ?? null;

        if (!in_array($mode, [IndicatorCalculationModeService::MODE_PHP, IndicatorCalculationModeService::MODE_SQL])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Mode invalide. Utilisez "php" ou "sql".'
            ], 400);
        }

        try {
            $this->modeService->setMode($mode);
            
            return new JsonResponse([
                'success' => true,
                'message' => "Mode de calcul changé vers: $mode",
                'current_mode' => $mode
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur lors du changement de mode: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère les métriques de performance
     */
    #[Route('/performance-metrics', name: 'performance_metrics', methods: ['GET'])]
    public function getPerformanceMetrics(): JsonResponse
    {
        $metrics = $this->modeService->getPerformanceMetrics();
        $summary = $this->modeService->getPerformanceSummary();

        return new JsonResponse([
            'metrics' => $metrics,
            'summary' => $summary
        ]);
    }

    /**
     * Réinitialise les métriques de performance
     */
    #[Route('/performance-metrics/reset', name: 'reset_performance_metrics', methods: ['POST'])]
    public function resetPerformanceMetrics(): JsonResponse
    {
        try {
            $this->modeService->resetPerformanceMetrics();
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Métriques de performance réinitialisées'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur lors de la réinitialisation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Rafraîchit les vues matérialisées SQL
     */
    #[Route('/sql/refresh', name: 'refresh_sql_views', methods: ['POST'])]
    public function refreshSqlViews(): JsonResponse
    {
        try {
            $this->sqlService->refreshMaterializedViews();
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Vues matérialisées rafraîchies avec succès'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur lors du rafraîchissement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Teste les performances des deux modes
     */
    #[Route('/test-performance', name: 'test_performance', methods: ['POST'])]
    public function testPerformance(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $symbol = $data['symbol'] ?? 'BTCUSDT';
        $timeframe = $data['timeframe'] ?? '5m';
        $iterations = $data['iterations'] ?? 10;

        $results = [
            'sql' => ['times' => [], 'avg_ms' => 0, 'success' => 0, 'errors' => 0],
            'php' => ['times' => [], 'avg_ms' => 0, 'success' => 0, 'errors' => 0]
        ];

        // Test SQL
        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            try {
                $this->sqlService->getEma($symbol, $timeframe, 1);
                $results['sql']['success']++;
            } catch (\Exception $e) {
                $results['sql']['errors']++;
            }
            $results['sql']['times'][] = (microtime(true) - $startTime) * 1000;
        }

        // Test PHP (simulation avec données factices)
        $testPrices = array_fill(0, 200, 100.0);
        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            try {
                // Simulation d'un calcul PHP simple
                usleep(1000); // 1ms de simulation
                $results['php']['success']++;
            } catch (\Exception $e) {
                $results['php']['errors']++;
            }
            $results['php']['times'][] = (microtime(true) - $startTime) * 1000;
        }

        // Calcul des moyennes
        $results['sql']['avg_ms'] = array_sum($results['sql']['times']) / count($results['sql']['times']);
        $results['php']['avg_ms'] = array_sum($results['php']['times']) / count($results['php']['times']);

        return new JsonResponse([
            'success' => true,
            'results' => $results,
            'recommendation' => $results['sql']['avg_ms'] < $results['php']['avg_ms'] ? 'sql' : 'php'
        ]);
    }

    /**
     * Vérifie l'état des vues matérialisées
     */
    #[Route('/sql/status', name: 'sql_status', methods: ['GET'])]
    public function getSqlStatus(): JsonResponse
    {
        $symbols = ['BTCUSDT', 'ETHUSDT', 'ADAUSDT'];
        $timeframes = ['5m', '15m', '1h'];
        $status = [];

        foreach ($symbols as $symbol) {
            foreach ($timeframes as $timeframe) {
                $hasData = $this->sqlService->hasData($symbol, $timeframe);
                $status["$symbol:$timeframe"] = $hasData;
            }
        }

        return new JsonResponse([
            'status' => $status,
            'overall_health' => array_sum($status) / count($status) > 0.8 ? 'good' : 'poor'
        ]);
    }
}
