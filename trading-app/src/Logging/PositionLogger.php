<?php

declare(strict_types=1);

namespace App\Logging;

use App\Domain\PostValidation\Dto\EntryZoneDto;
use App\Domain\PostValidation\Dto\OrderPlanDto;
use App\Domain\PostValidation\Dto\MarketDataDto;
use App\Domain\PostValidation\Dto\PostValidationDecisionDto;
use Psr\Log\LoggerInterface;

/**
 * Logger spécialisé pour le suivi des positions depuis la validation 1m jusqu'à l'ouverture effective
 * 
 * Suit le parcours complet :
 * 1. Validation 1m (signal validé)
 * 2. Calcul EntryZone
 * 3. Création OrderPlan
 * 4. Exécution des garde-fous
 * 5. Soumission des ordres
 * 6. Attente du fill
 * 7. Attachement TP/SL
 * 8. Ouverture effective de la position
 */
final class PositionLogger
{
    public function __construct(
        private readonly LoggerInterface $positionsLogger,
        private readonly LoggerInterface $postValidationLogger
    ) {
    }

    /**
     * Log du début du processus Post-Validation
     */
    public function logPostValidationStart(
        string $symbol,
        string $side,
        array $mtfContext,
        float $walletEquity
    ): void {
        $this->positionsLogger->info('=== POST-VALIDATION START ===', [
            'symbol' => $symbol,
            'side' => $side,
            'mtf_context' => $mtfContext,
            'wallet_equity' => $walletEquity,
            'timestamp' => time()
        ]);
    }

    /**
     * Log de la récupération des données de marché
     */
    public function logMarketDataRetrieved(MarketDataDto $marketData): void
    {
        $this->positionsLogger->info('[MARKET_DATA] Retrieved', [
            'symbol' => $marketData->symbol,
            'last_price' => $marketData->lastPrice,
            'bid_price' => $marketData->bidPrice,
            'ask_price' => $marketData->askPrice,
            'spread_bps' => $marketData->spreadBps,
            'depth_top_usd' => $marketData->depthTopUsd,
            'vwap' => $marketData->vwap,
            'atr_1m' => $marketData->atr1m,
            'atr_5m' => $marketData->atr5m,
            'is_stale' => $marketData->isStale,
            'data_age_seconds' => $marketData->getPriceAgeSeconds()
        ]);
    }

    /**
     * Log de la sélection du timeframe d'exécution
     */
    public function logExecutionTimeframeSelected(
        string $symbol,
        string $selectedTimeframe,
        array $selectionCriteria
    ): void {
        $this->positionsLogger->info('[TIMEFRAME_SELECTION] Selected', [
            'symbol' => $symbol,
            'selected_timeframe' => $selectedTimeframe,
            'criteria' => $selectionCriteria,
            'timestamp' => time()
        ]);
    }

    /**
     * Log du calcul de la zone d'entrée
     */
    public function logEntryZoneCalculated(EntryZoneDto $entryZone): void
    {
        $this->positionsLogger->info('[ENTRY_ZONE] Calculated', [
            'symbol' => $entryZone->symbol,
            'side' => $entryZone->side,
            'entry_min' => $entryZone->entryMin,
            'entry_max' => $entryZone->entryMax,
            'mid_price' => $entryZone->getMidPrice(),
            'zone_width' => $entryZone->zoneWidth,
            'zone_width_bps' => $entryZone->getZoneWidthBps(),
            'vwap_anchor' => $entryZone->vwapAnchor,
            'atr_value' => $entryZone->atrValue,
            'spread_bps' => $entryZone->spreadBps,
            'depth_top_usd' => $entryZone->depthTopUsd,
            'quality_passed' => $entryZone->qualityPassed,
            'evidence' => $entryZone->evidence
        ]);
    }

    /**
     * Log de la création du plan d'ordres
     */
    public function logOrderPlanCreated(OrderPlanDto $orderPlan): void
    {
        $this->positionsLogger->info('[ORDER_PLAN] Created', [
            'symbol' => $orderPlan->symbol,
            'side' => $orderPlan->side,
            'execution_timeframe' => $orderPlan->executionTimeframe,
            'quantity' => $orderPlan->quantity,
            'leverage' => $orderPlan->leverage,
            'total_notional' => $orderPlan->getTotalNotional(),
            'entry_price' => $orderPlan->getEntryPrice(),
            'risk_amount' => $orderPlan->getRiskAmount(),
            'stop_loss_price' => $orderPlan->getStopLossPrice(),
            'take_profit_price' => $orderPlan->getTakeProfitPrice(),
            'client_order_id' => $orderPlan->clientOrderId,
            'decision_key' => $orderPlan->decisionKey,
            'maker_orders_count' => count($orderPlan->makerOrders),
            'fallback_orders_count' => count($orderPlan->fallbackOrders),
            'tp_sl_orders_count' => count($orderPlan->tpSlOrders)
        ]);
    }

    /**
     * Log de l'exécution des garde-fous
     */
    public function logGuardsExecution(
        string $symbol,
        array $guardsResult
    ): void {
        $this->positionsLogger->info('[GUARDS] Execution completed', [
            'symbol' => $symbol,
            'all_passed' => $guardsResult['all_passed'],
            'failed_count' => $guardsResult['failed_count'],
            'guards' => $guardsResult['guards'],
            'timestamp' => time()
        ]);
    }

    /**
     * Log du début de la machine d'états
     */
    public function logStateMachineStart(
        string $symbol,
        string $side,
        string $initialState
    ): void {
        $this->positionsLogger->info('[STATE_MACHINE] Starting sequence', [
            'symbol' => $symbol,
            'side' => $side,
            'initial_state' => $initialState,
            'timestamp' => time()
        ]);
    }

    /**
     * Log d'une transition d'état
     */
    public function logStateTransition(
        string $symbol,
        string $fromState,
        string $action,
        string $toState,
        array $data = []
    ): void {
        $this->positionsLogger->info('[STATE_MACHINE] Transition', [
            'symbol' => $symbol,
            'from_state' => $fromState,
            'action' => $action,
            'to_state' => $toState,
            'data' => $data,
            'timestamp' => time()
        ]);
    }

    /**
     * Log de la soumission d'un ordre maker
     */
    public function logMakerOrderSubmitted(
        string $symbol,
        string $clientOrderId,
        array $orderData,
        array $response
    ): void {
        $this->positionsLogger->info('[MAKER_ORDER] Submitted', [
            'symbol' => $symbol,
            'client_order_id' => $clientOrderId,
            'order_data' => $orderData,
            'response' => $response,
            'timestamp' => time()
        ]);
    }

    /**
     * Log de l'attente du fill
     */
    public function logWaitingForFill(
        string $symbol,
        string $clientOrderId,
        int $timeoutSeconds
    ): void {
        $this->positionsLogger->info('[MAKER_ORDER] Waiting for fill', [
            'symbol' => $symbol,
            'client_order_id' => $clientOrderId,
            'timeout_seconds' => $timeoutSeconds,
            'timestamp' => time()
        ]);
    }

    /**
     * Log du résultat du fill
     */
    public function logFillResult(
        string $symbol,
        string $clientOrderId,
        array $fillResult
    ): void {
        $this->positionsLogger->info('[MAKER_ORDER] Fill result', [
            'symbol' => $symbol,
            'client_order_id' => $clientOrderId,
            'status' => $fillResult['status'],
            'order_id' => $fillResult['order_id'] ?? null,
            'filled_qty' => $fillResult['filled_qty'] ?? null,
            'avg_price' => $fillResult['avg_price'] ?? null,
            'timestamp' => time()
        ]);
    }

    /**
     * Log de l'annulation d'un ordre maker
     */
    public function logMakerOrderCancelled(
        string $symbol,
        string $clientOrderId,
        array $cancelResult
    ): void {
        $this->positionsLogger->info('[MAKER_ORDER] Cancelled', [
            'symbol' => $symbol,
            'client_order_id' => $clientOrderId,
            'status' => $cancelResult['status'],
            'order_id' => $cancelResult['order_id'] ?? null,
            'timestamp' => time()
        ]);
    }

    /**
     * Log de la soumission d'un ordre taker (fallback)
     */
    public function logTakerOrderSubmitted(
        string $symbol,
        array $orderData,
        array $response
    ): void {
        $this->positionsLogger->info('[TAKER_ORDER] Submitted (fallback)', [
            'symbol' => $symbol,
            'order_data' => $orderData,
            'response' => $response,
            'timestamp' => time()
        ]);
    }

    /**
     * Log de l'attachement des ordres TP/SL
     */
    public function logTpSlAttached(
        string $symbol,
        array $tpSlResults
    ): void {
        $this->positionsLogger->info('[TP_SL] Attached', [
            'symbol' => $symbol,
            'tp_sl_results' => $tpSlResults,
            'timestamp' => time()
        ]);
    }

    /**
     * Log de l'ouverture effective de la position
     */
    public function logPositionOpened(
        string $symbol,
        string $side,
        float $quantity,
        float $leverage,
        float $entryPrice,
        array $positionData
    ): void {
        $this->positionsLogger->info('[POSITION] Opened successfully', [
            'symbol' => $symbol,
            'side' => $side,
            'quantity' => $quantity,
            'leverage' => $leverage,
            'entry_price' => $entryPrice,
            'notional' => $quantity * $entryPrice * $leverage,
            'position_data' => $positionData,
            'timestamp' => time()
        ]);
    }

    /**
     * Log du démarrage du monitoring
     */
    public function logMonitoringStarted(
        string $symbol,
        array $monitoringData
    ): void {
        $this->positionsLogger->info('[MONITORING] Started', [
            'symbol' => $symbol,
            'monitoring_data' => $monitoringData,
            'timestamp' => time()
        ]);
    }

    /**
     * Log de la décision finale
     */
    public function logFinalDecision(PostValidationDecisionDto $decision): void
    {
        $this->positionsLogger->info('[FINAL_DECISION] Post-validation completed', [
            'decision' => $decision->decision,
            'reason' => $decision->reason,
            'symbol' => $decision->getSymbol(),
            'side' => $decision->getSide(),
            'execution_timeframe' => $decision->getExecutionTimeframe(),
            'decision_key' => $decision->decisionKey,
            'timestamp' => $decision->timestamp
        ]);

        if ($decision->isOpen()) {
            $this->positionsLogger->info('[FINAL_DECISION] Position opening details', [
                'symbol' => $decision->getSymbol(),
                'entry_zone' => $decision->entryZone?->toArray(),
                'order_plan' => $decision->orderPlan?->toArray(),
                'guards' => $decision->guards,
                'evidence' => $decision->evidence
            ]);
        } else {
            $this->positionsLogger->info('[FINAL_DECISION] Position skipped', [
                'symbol' => $decision->getSymbol(),
                'reason' => $decision->reason,
                'market_data' => $decision->marketData,
                'guards' => $decision->guards,
                'evidence' => $decision->evidence
            ]);
        }
    }

    /**
     * Log d'erreur
     */
    public function logError(
        string $context,
        string $symbol,
        string $error,
        array $contextData = []
    ): void {
        $this->positionsLogger->error('[ERROR] ' . $context, [
            'symbol' => $symbol,
            'error' => $error,
            'context_data' => $contextData,
            'timestamp' => time()
        ]);
    }

    /**
     * Log d'avertissement
     */
    public function logWarning(
        string $context,
        string $symbol,
        string $warning,
        array $contextData = []
    ): void {
        $this->positionsLogger->warning('[WARNING] ' . $context, [
            'symbol' => $symbol,
            'warning' => $warning,
            'context_data' => $contextData,
            'timestamp' => time()
        ]);
    }

    /**
     * Log de debug
     */
    public function logDebug(
        string $context,
        string $symbol,
        array $debugData
    ): void {
        $this->positionsLogger->debug('[DEBUG] ' . $context, [
            'symbol' => $symbol,
            'debug_data' => $debugData,
            'timestamp' => time()
        ]);
    }

    /**
     * Log de métriques de performance
     */
    public function logPerformanceMetrics(
        string $symbol,
        array $metrics
    ): void {
        $this->positionsLogger->info('[PERFORMANCE] Metrics', [
            'symbol' => $symbol,
            'metrics' => $metrics,
            'timestamp' => time()
        ]);
    }
}

