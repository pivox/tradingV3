<?php
declare(strict_types=1);

namespace App\TradeEntry\Hook;

use App\Contract\Runtime\AuditLoggerInterface;
use App\MtfValidator\Repository\MtfSwitchRepository;
use App\TradeEntry\Dto\{ExecutionResult, TradeEntryRequest};
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook post-exécution spécifique MTF.
 * Gère les switches et l'audit après soumission d'ordres depuis MTF.
 */
final class MtfPostExecutionHook implements PostExecutionHookInterface
{
    public function __construct(
        private readonly MtfSwitchRepository $mtfSwitchRepository,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly bool $isDryRun,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {}

    public function onSubmitted(
        TradeEntryRequest $request,
        ExecutionResult $result,
        ?string $decisionKey = null
    ): void {
        // En mode dry-run, ne pas modifier les switches
        if ($this->isDryRun) {
            return;
        }

        // Désactiver le symbole après soumission pour éviter les ré-entrées immédiates
        try {
        
            $this->mtfSwitchRepository->turnOffSymbolForDuration($request->symbol, duration: '5m');
            $this->positionsLogger->info('[MtfPostExecutionHook] Symbol switched OFF for 15 minutes after order', [
                'symbol' => $request->symbol,
                'duration' => '15 minutes',
                'reason' => 'order_submitted',
                'decision_key' => $decisionKey,
            ]);
        } catch (\Throwable $e) {
            $this->positionsLogger->error('[MtfPostExecutionHook] Failed to switch OFF symbol', [
                'symbol' => $request->symbol,
                'error' => $e->getMessage(),
                'decision_key' => $decisionKey,
            ]);
        }

        // Audit
        $this->auditLogger->logAction(
            'TRADE_ENTRY_EXECUTED',
            'TRADE_ENTRY',
            $request->symbol,
            [
                'status' => $result->status,
                'client_order_id' => $result->clientOrderId,
                'exchange_order_id' => $result->exchangeOrderId,
                'order_type' => $request->orderType,
                'decision_key' => $decisionKey,
            ]
        );
    }

    public function onSimulated(
        TradeEntryRequest $request,
        ExecutionResult $result,
        ?string $decisionKey = null
    ): void {
        // Audit pour simulation
        $this->auditLogger->logAction(
            'TRADE_ENTRY_SIMULATED',
            'TRADE_ENTRY',
            $request->symbol,
            [
                'status' => $result->status,
                'client_order_id' => $result->clientOrderId,
                'order_type' => $request->orderType,
                'decision_key' => $decisionKey,
            ]
        );
    }
}

