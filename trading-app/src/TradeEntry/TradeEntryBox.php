<?php
declare(strict_types=1);

namespace App\TradeEntry;

use App\TradeEntry\PreOrder\PreOrderBuilder;
use App\TradeEntry\EntryZone\EntryZoneBox;
use App\TradeEntry\RiskSizer\RiskSizerBox;
use App\TradeEntry\OrderPlan\OrderPlanBox;
use App\TradeEntry\Execution\ExecutionBox;
use App\TradeEntry\Execution\ExecutionResult;
use App\TradeEntry\Service\TradeEntryMetricsService;
use App\TradeEntry\Service\TradeEntryAlertService;
use Psr\Log\LoggerInterface;
 
final class TradeEntryBox
{
    public function __construct(
        private PreOrderBuilder $preOrder,
        private EntryZoneBox $entryZone,
        private RiskSizerBox $riskSizer,
        private OrderPlanBox $orderPlan,
        private ExecutionBox $executor,
        private TradeEntryMetricsService $metricsService,
        private TradeEntryAlertService $alertService,
        private LoggerInterface $logger
    ) {}

    /**
     * @param array $input voir TradeEntryRequest::__construct pour les clés
     */
    public function handle(array $input): ExecutionResult
    {
        $this->logger->info('TradeEntry: Starting trade entry process', [
            'symbol' => $input['symbol'] ?? 'unknown',
            'side' => $input['side'] ?? 'unknown'
        ]);

        try {
            $req = $this->preOrder->build($input);
            $this->logger->debug('TradeEntry: PreOrder built', [
                'symbol' => $req->symbol,
                'side' => $req->side->value,
                'entry_price' => $req->entryPriceBase
            ]);

            $zone = $this->entryZone->compute($req);
            if ($zone === null) {
                $this->logger->warning('TradeEntry: Entry zone invalid or filters failed', [
                    'symbol' => $req->symbol,
                    'side' => $req->side->value
                ]);
                return ExecutionResult::cancelled('entry_zone_invalid_or_filters_failed');
            }

            $this->logger->debug('TradeEntry: Entry zone computed', [
                'zone_low' => $zone->low,
                'zone_high' => $zone->high,
                'expires_at' => $zone->expiresAt->format('Y-m-d H:i:s')
            ]);

            $risk = $this->riskSizer->compute($req, $zone);
            $this->logger->debug('TradeEntry: Risk computed', [
                'leverage' => $risk->leverage,
                'quantity' => $risk->quantity,
                'risk_usdt' => $risk->riskUsdt
            ]);

            $plan = $this->orderPlan->build($req, $zone, $risk);
            $this->logger->debug('TradeEntry: Order plan built', [
                'entry_price' => $plan->entryPrice,
                'sl_price' => $plan->slPrice,
                'tp1_price' => $plan->tp1Price
            ]);

            $result = $this->executor->execute($plan);
            
            // Enregistrement des métriques et vérification des alertes
            $executionTime = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
            $this->metricsService->recordExecution(
                $req->symbol,
                $req->side->value,
                $executionTime,
                $result,
                $input
            );

            $this->alertService->checkAlerts(
                $req->symbol,
                $req->side->value,
                $executionTime,
                $result,
                $this->metricsService->getMetrics()
            );
            
            $this->logger->info('TradeEntry: Process completed', [
                'status' => $result->status,
                'symbol' => $req->symbol,
                'execution_time' => $executionTime
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('TradeEntry: Process failed', [
                'error' => $e->getMessage(),
                'symbol' => $input['symbol'] ?? 'unknown'
            ]);

            return ExecutionResult::cancelled('process_error', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Récupère les métriques de performance
     */
    public function getMetrics(): array
    {
        return $this->metricsService->getMetrics();
    }

    /**
     * Réinitialise les métriques
     */
    public function resetMetrics(): void
    {
        $this->metricsService->resetMetrics();
    }

    /**
     * Récupère les alertes récentes
     */
    public function getRecentAlerts(): array
    {
        return [
            'recent_executions' => $this->alertService->getRecentExecutions(),
            'consecutive_failures' => $this->alertService->getConsecutiveFailures(),
            'alert_thresholds' => $this->alertService->getAlertThresholds()
        ];
    }
}
