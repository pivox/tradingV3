<?php

declare(strict_types=1);

namespace App\Domain\PostValidation\Service;

use App\Domain\PostValidation\Dto\PostValidationDecisionDto;
use App\Domain\PostValidation\Dto\MarketDataDto;
use App\Domain\PostValidation\Dto\EntryZoneDto;
use App\Domain\PostValidation\Dto\OrderPlanDto;
use App\Config\TradingParameters;
use App\Logging\PositionLogger;
use Psr\Log\LoggerInterface;

/**
 * Service principal de Post-Validation
 * 
 * Orchestre tous les services pour transformer un signal validé (MTF) 
 * en plans d'exécution sûrs, traçables et idempotents
 */
final class PostValidationService
{
    public function __construct(
        private readonly MarketDataProvider $marketDataProvider,
        private readonly ExecutionTimeframeSelector $timeframeSelector,
        private readonly EntryZoneService $entryZoneService,
        private readonly PositionOpener $positionOpener,
        private readonly PostValidationGuards $guards,
        private readonly PostValidationStateMachine $stateMachine,
        private readonly TradingParameters $config,
        private readonly LoggerInterface $logger,
        private readonly PositionLogger $positionLogger
    ) {
    }

    /**
     * Exécute l'étape Post-Validation complète
     */
    public function executePostValidation(
        string $symbol,
        string $side,
        array $mtfContext,
        float $walletEquity = 1000.0
    ): PostValidationDecisionDto {
        $this->logger->info('[PostValidation] Starting post-validation', [
            'symbol' => $symbol,
            'side' => $side,
            'wallet_equity' => $walletEquity
        ]);

        // Log du début du processus
        $this->positionLogger->logPostValidationStart($symbol, $side, $mtfContext, $walletEquity);

        try {
            // 1. Récupération des données de marché
            $marketData = $this->marketDataProvider->getMarketData($symbol);
            
            // Log des données de marché récupérées
            $this->positionLogger->logMarketDataRetrieved($marketData);
            
            // 2. Sélection du timeframe d'exécution
            $executionTimeframe = $this->timeframeSelector->selectExecutionTimeframe($marketData, $mtfContext);
            
            // Log de la sélection du timeframe
            $this->positionLogger->logExecutionTimeframeSelected($symbol, $executionTimeframe, [
                'atr_1m_pct' => $marketData->lastPrice > 0 ? ($marketData->atr1m / $marketData->lastPrice) * 100 : 0,
                'atr_5m_pct' => $marketData->lastPrice > 0 ? ($marketData->atr5m / $marketData->lastPrice) * 100 : 0,
                'spread_bps' => $marketData->spreadBps,
                'depth_top_usd' => $marketData->depthTopUsd
            ]);
            
            // 3. Calcul de la zone d'entrée
            $entryZone = $this->entryZoneService->calculateEntryZone(
                $symbol,
                $side,
                $marketData,
                $executionTimeframe
            );
            
            // Log de la zone d'entrée calculée
            $this->positionLogger->logEntryZoneCalculated($entryZone);
            
            // 4. Vérification des garde-fous préliminaires
            if (!$entryZone->qualityPassed) {
                return $this->createSkipDecision(
                    $symbol,
                    $side,
                    $marketData,
                    'Entry zone quality filters failed',
                    $entryZone->evidence
                );
            }
            
            // 5. Création du plan d'ordres
            $orderPlan = $this->positionOpener->createOrderPlan(
                $entryZone,
                $marketData,
                $executionTimeframe,
                $mtfContext,
                $walletEquity
            );
            
            // Log de la création du plan d'ordres
            $this->positionLogger->logOrderPlanCreated($orderPlan);
            
            // 6. Exécution des garde-fous finaux
            $guardsResult = $this->guards->executeGuards($marketData, $entryZone, $orderPlan);
            
            // Log de l'exécution des garde-fous
            $this->positionLogger->logGuardsExecution($symbol, $guardsResult);
            
            if (!$guardsResult['all_passed']) {
                return $this->createSkipDecision(
                    $symbol,
                    $side,
                    $marketData,
                    'Guards failed: ' . implode(', ', array_keys(array_filter($guardsResult['guards'], fn($g) => !$g['passed']))),
                    array_merge($entryZone->evidence, ['guards' => $guardsResult])
                );
            }
            
            // 7. Exécution de la séquence via la machine d'états
            $decision = $this->stateMachine->executeSequence($entryZone, $orderPlan, $marketData);
            
            // Log de la décision finale
            $this->positionLogger->logFinalDecision($decision);
            
            $this->logger->info('[PostValidation] Post-validation completed', [
                'symbol' => $symbol,
                'side' => $side,
                'decision' => $decision->decision,
                'reason' => $decision->reason
            ]);
            
            return $decision;
            
        } catch (\Exception $e) {
            $this->logger->error('[PostValidation] Post-validation failed', [
                'symbol' => $symbol,
                'side' => $side,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Log de l'erreur
            $this->positionLogger->logError('Post-validation execution', $symbol, $e->getMessage(), [
                'side' => $side,
                'mtf_context' => $mtfContext,
                'wallet_equity' => $walletEquity,
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->createSkipDecision(
                $symbol,
                $side,
                $marketData ?? null,
                'Post-validation error: ' . $e->getMessage(),
                ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
            );
        }
    }

    /**
     * Exécute Post-Validation en mode dry-run (sans exécution d'ordres)
     */
    public function executePostValidationDryRun(
        string $symbol,
        string $side,
        array $mtfContext,
        float $walletEquity = 1000.0
    ): PostValidationDecisionDto {
        $this->logger->info('[PostValidation] Starting dry-run post-validation', [
            'symbol' => $symbol,
            'side' => $side,
            'wallet_equity' => $walletEquity
        ]);

        try {
            // 1. Récupération des données de marché
            $marketData = $this->marketDataProvider->getMarketData($symbol);
            
            // 2. Sélection du timeframe d'exécution
            $executionTimeframe = $this->timeframeSelector->selectExecutionTimeframe($marketData, $mtfContext);
            
            // 3. Calcul de la zone d'entrée
            $entryZone = $this->entryZoneService->calculateEntryZone(
                $symbol,
                $side,
                $marketData,
                $executionTimeframe
            );
            
            // 4. Vérification des garde-fous préliminaires
            if (!$entryZone->qualityPassed) {
                return $this->createSkipDecision(
                    $symbol,
                    $side,
                    $marketData,
                    'Entry zone quality filters failed (dry-run)',
                    $entryZone->evidence
                );
            }
            
            // 5. Création du plan d'ordres (sans exécution)
            $orderPlan = $this->positionOpener->createOrderPlan(
                $entryZone,
                $marketData,
                $executionTimeframe,
                $mtfContext,
                $walletEquity
            );
            
            // 6. Exécution des garde-fous finaux
            $guardsResult = $this->guards->executeGuards($marketData, $entryZone, $orderPlan);
            
            if (!$guardsResult['all_passed']) {
                return $this->createSkipDecision(
                    $symbol,
                    $side,
                    $marketData,
                    'Guards failed (dry-run): ' . implode(', ', array_keys(array_filter($guardsResult['guards'], fn($g) => !$g['passed']))),
                    array_merge($entryZone->evidence, ['guards' => $guardsResult])
                );
            }
            
            // 7. Simulation de succès (dry-run)
            $decision = $this->createSuccessDecision(
                $symbol,
                $side,
                $marketData,
                $entryZone,
                $orderPlan,
                'Dry-run completed successfully'
            );
            
            $this->logger->info('[PostValidation] Dry-run post-validation completed', [
                'symbol' => $symbol,
                'side' => $side,
                'decision' => $decision->decision,
                'reason' => $decision->reason
            ]);
            
            return $decision;
            
        } catch (\Exception $e) {
            $this->logger->error('[PostValidation] Dry-run post-validation failed', [
                'symbol' => $symbol,
                'side' => $side,
                'error' => $e->getMessage()
            ]);
            
            return $this->createSkipDecision(
                $symbol,
                $side,
                $marketData ?? null,
                'Dry-run error: ' . $e->getMessage(),
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Vérifie l'idempotence d'une décision
     */
    public function checkIdempotence(string $decisionKey): ?PostValidationDecisionDto
    {
        // TODO: Implémenter la vérification d'idempotence via la base de données
        // Pour l'instant, retourne null (pas de décision existante)
        return null;
    }

    /**
     * Obtient les statistiques de Post-Validation
     */
    public function getStatistics(): array
    {
        // TODO: Implémenter les statistiques
        return [
            'total_decisions' => 0,
            'open_decisions' => 0,
            'skip_decisions' => 0,
            'success_rate' => 0.0,
            'avg_execution_time' => 0.0
        ];
    }

    /**
     * Crée une décision de succès
     */
    private function createSuccessDecision(
        string $symbol,
        string $side,
        MarketDataDto $marketData,
        EntryZoneDto $entryZone,
        OrderPlanDto $orderPlan,
        string $reason
    ): PostValidationDecisionDto {
        return new PostValidationDecisionDto(
            decision: PostValidationDecisionDto::DECISION_OPEN,
            reason: $reason,
            entryZone: $entryZone,
            orderPlan: $orderPlan,
            marketData: $marketData->toArray(),
            guards: ['all_passed' => true],
            evidence: [
                'entry_zone' => $entryZone->evidence,
                'order_plan' => $orderPlan->evidence,
                'market_data' => $marketData->toArray()
            ],
            decisionKey: $orderPlan->decisionKey,
            timestamp: time()
        );
    }

    /**
     * Crée une décision de skip
     */
    private function createSkipDecision(
        string $symbol,
        string $side,
        ?MarketDataDto $marketData,
        string $reason,
        array $evidence = []
    ): PostValidationDecisionDto {
        $decisionKey = sprintf('%s:%s:%d', $symbol, '5m', time());
        
        return new PostValidationDecisionDto(
            decision: PostValidationDecisionDto::DECISION_SKIP,
            reason: $reason,
            entryZone: null,
            orderPlan: null,
            marketData: $marketData?->toArray() ?? [],
            guards: ['all_passed' => false],
            evidence: $evidence,
            decisionKey: $decisionKey,
            timestamp: time()
        );
    }
}
