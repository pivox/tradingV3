<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Common\Enum\SignalSide;
use App\Contract\EntryTrade\TradeContextInterface;
use App\Contract\EntryTrade\TradingDecisionInterface;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Runtime\AuditLoggerInterface;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\Service\Price\TradingPriceResolver;
use Psr\Log\LoggerInterface;

/**
 * Gestionnaire des décisions de trading optimisé
 */
final class TradingDecisionHandler
{
    public function __construct(
        private readonly TradingDecisionInterface $tradingDecisionService,
        private readonly TradeContextInterface $tradeContext,
        private readonly TradingPriceResolver $tradingPriceResolver,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $positionsFlowLogger
    ) {}

    /**
     * Gère la décision de trading pour un symbole
     */
    public function handleTradingDecision(SymbolResultDto $symbolResult, MtfRunDto $mtfRunDto): SymbolResultDto
    {
        if ($symbolResult->isError() || $symbolResult->isSkipped()) {
            return $symbolResult;
        }

        $status = strtoupper($symbolResult->status);
        if ($status !== 'READY') {
            return $symbolResult;
        }

        // Vérifier le contexte de trading
        $accountBalance = 0.0;
        $riskPercentage = 0.0;
        
        try {
            $accountBalance = max(0.0, $this->tradeContext->getAccountBalance());
            $riskPercentage = max(0.0, $this->tradeContext->getRiskPercentage());
        } catch (\Throwable $e) {
            $this->logger->warning('[Trading Decision] Unable to resolve trading context', [
                'symbol' => $symbolResult->symbol,
                'error' => $e->getMessage()
            ]);
            
            return $this->createSkippedResult($symbolResult, 'missing_trading_context');
        }

        if ($accountBalance <= 0.0 || $riskPercentage <= 0.0) {
            return $this->createSkippedResult($symbolResult, 'missing_trading_context');
        }

        // Vérifier les conditions de trading
        if (!$this->canExecuteTrading($symbolResult)) {
            return $this->createSkippedResult($symbolResult, 'trading_conditions_not_met');
        }

        // Résoudre le prix
        $priceResolution = $this->resolveTradingPrice($symbolResult);
        if ($priceResolution === null) {
            return $this->createSkippedResult($symbolResult, 'price_resolution_failed');
        }

        // Exécuter la décision de trading
        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Executing trading decision', [
                'symbol' => $symbolResult->symbol,
                'execution_tf' => $symbolResult->executionTf
            ]);

            $decision = $this->tradingDecisionService->makeTradingDecision(
                $symbolResult->symbol,
                SignalSide::from($symbolResult->signalSide),
                $priceResolution->price,
                $symbolResult->atr,
                $accountBalance,
                $riskPercentage,
                $this->isHighConviction($symbolResult),
                $this->tradeContext->getTimeframeMultiplier($symbolResult->executionTf)
            );

            $this->logTradingDecision($symbolResult->symbol, $decision);

            return new SymbolResultDto(
                symbol: $symbolResult->symbol,
                status: $symbolResult->status,
                executionTf: $symbolResult->executionTf,
                signalSide: $symbolResult->signalSide,
                tradingDecision: $decision,
                error: $symbolResult->error,
                context: $symbolResult->context,
                currentPrice: $symbolResult->currentPrice,
                atr: $symbolResult->atr
            );

        } catch (\Throwable $e) {
            $this->logger->error('[Trading Decision] Failed to execute trading decision', [
                'symbol' => $symbolResult->symbol,
                'error' => $e->getMessage()
            ]);

            $this->positionsFlowLogger->error('[PositionsFlow] Trading decision failed', [
                'symbol' => $symbolResult->symbol,
                'error' => $e->getMessage()
            ]);

            return new SymbolResultDto(
                symbol: $symbolResult->symbol,
                status: $symbolResult->status,
                executionTf: $symbolResult->executionTf,
                signalSide: $symbolResult->signalSide,
                tradingDecision: [
                    'status' => 'error',
                    'error' => $e->getMessage()
                ],
                error: $symbolResult->error,
                context: $symbolResult->context,
                currentPrice: $symbolResult->currentPrice,
                atr: $symbolResult->atr
            );
        }
    }

    private function canExecuteTrading(SymbolResultDto $symbolResult): bool
    {
        // Vérifier le timeframe d'exécution
        if ($symbolResult->executionTf !== '1m') {
            $this->logger->info('[Trading Decision] Skipping trading decision (execution_tf not 1m)', [
                'symbol' => $symbolResult->symbol,
                'execution_tf' => $symbolResult->executionTf
            ]);
            return false;
        }

        // Vérifier les données requises
        if ($symbolResult->currentPrice === null || $symbolResult->atr === null) {
            $this->logger->debug('[Trading Decision] Missing price or ATR', [
                'symbol' => $symbolResult->symbol,
                'has_price' => $symbolResult->currentPrice !== null,
                'has_atr' => $symbolResult->atr !== null
            ]);
            return false;
        }

        return true;
    }

    private function resolveTradingPrice(SymbolResultDto $symbolResult): ?object
    {
        try {
            return $this->tradingPriceResolver->resolve(
                $symbolResult->symbol,
                SignalSide::from($symbolResult->signalSide),
                $symbolResult->currentPrice,
                $symbolResult->atr
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[Trading Decision] Unable to resolve trading price', [
                'symbol' => $symbolResult->symbol,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function isHighConviction(SymbolResultDto $symbolResult): bool
    {
        $context = $symbolResult->context ?? [];
        $aligned = ($context['context_fully_aligned'] ?? false) === true;
        $contextDir = strtoupper($context['context_dir'] ?? 'NONE');
        $signalSide = strtoupper($symbolResult->signalSide ?? 'NONE');

        return $aligned && $contextDir !== 'NONE' && $contextDir === $signalSide;
    }

    private function createSkippedResult(SymbolResultDto $symbolResult, string $reason): SymbolResultDto
    {
        return new SymbolResultDto(
            symbol: $symbolResult->symbol,
            status: $symbolResult->status,
            executionTf: $symbolResult->executionTf,
            signalSide: $symbolResult->signalSide,
            tradingDecision: [
                'status' => 'skipped',
                'reason' => $reason
            ],
            error: $symbolResult->error,
            context: $symbolResult->context,
            currentPrice: $symbolResult->currentPrice,
            atr: $symbolResult->atr
        );
    }

    private function logTradingDecision(string $symbol, array $decision): void
    {
        try {
            $this->positionsFlowLogger->info('[PositionsFlow] Trading decision executed', [
                'symbol' => $symbol,
                'status' => $decision['status'] ?? null,
                'reason' => $decision['reason'] ?? null
            ]);

            // Log audit
            $this->auditLogger->logTradingAction(
                'TRADING_DECISION',
                $symbol,
                0.0, // quantity sera dans la décision
                0.0, // price sera dans la décision
                $decision['execution_result']['main_order']['order_id'] ?? null
            );
        } catch (\Throwable $e) {
            $this->logger->error('[Trading Decision] Failed to log trading decision', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
        }
    }
}
