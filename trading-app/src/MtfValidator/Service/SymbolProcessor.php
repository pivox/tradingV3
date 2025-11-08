<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Service\MtfService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Processeur de symboles optimisé pour les performances
 */
final class SymbolProcessor
{
    public function __construct(
        private readonly MtfService $mtfService,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'monolog.logger.mtf')] private readonly LoggerInterface $orderJourneyLogger,
    ) {}

    /**
     * Traite un symbole individuel
     */
    public function processSymbol(
        string $symbol,
        UuidInterface $runId,
        MtfRunDto $mtfRunDto,
        \DateTimeImmutable $now
    ): SymbolResultDto {
        $this->logger->debug('[Symbol Processor] Processing symbol', [
            'symbol' => $symbol,
            'run_id' => $runId->toString(),
            'force_run' => $mtfRunDto->forceRun,
            'force_timeframe_check' => $mtfRunDto->forceTimeframeCheck
        ]);
        $decisionKey = sprintf('symbol:%s:%s', strtoupper($symbol), $runId->toString());
        $this->orderJourneyLogger->info('order_journey.symbol_processor.start', [
            'symbol' => $symbol,
            'run_id' => $runId->toString(),
            'decision_key' => $decisionKey,
            'reason' => 'mtf_symbol_processing_begin',
        ]);

        try {
            $mtfGenerator = $this->mtfService->runForSymbol(
                $runId,
                $symbol,
                $now,
                $mtfRunDto->currentTf,
                $mtfRunDto->forceTimeframeCheck,
                $mtfRunDto->forceRun,
                $mtfRunDto->skipContextValidation
            );

            // Consommer le generator et récupérer le résultat
            $result = null;
            foreach ($mtfGenerator as $mtfYieldedData) {
                $result = $mtfYieldedData['result'];
            }

            // Récupérer le résultat final
            $finalResult = $mtfGenerator->getReturn();
            $symbolResult = $finalResult ?? $result;

            if ($symbolResult === null) {
                $this->orderJourneyLogger->error('order_journey.symbol_processor.no_result', [
                    'symbol' => $symbol,
                    'run_id' => $runId->toString(),
                    'decision_key' => $decisionKey,
                    'reason' => 'mtf_service_returned_null',
                ]);
                return new SymbolResultDto(
                    symbol: $symbol,
                    status: 'ERROR',
                    error: ['message' => 'No result from MTF service']
                );
            }

            $this->orderJourneyLogger->info('order_journey.symbol_processor.completed', [
                'symbol' => $symbol,
                'run_id' => $runId->toString(),
                'decision_key' => $decisionKey,
                'status' => $symbolResult['status'] ?? 'UNKNOWN',
                'execution_tf' => $symbolResult['execution_tf'] ?? null,
                'signal_side' => $symbolResult['signal_side'] ?? null,
                'reason' => 'mtf_symbol_processing_end',
            ]);

            return new SymbolResultDto(
                symbol: $symbol,
                status: $symbolResult['status'] ?? 'UNKNOWN',
                executionTf: $symbolResult['execution_tf'] ?? null,
                failedTimeframe: $symbolResult['failed_timeframe'] ?? null,
                signalSide: $symbolResult['signal_side'] ?? null,
                tradingDecision: $symbolResult['trading_decision'] ?? null,
                error: $symbolResult['error'] ?? null,
                context: $symbolResult['context'] ?? null,
                currentPrice: $symbolResult['current_price'] ?? null,
                atr: $symbolResult['atr'] ?? null
            );

        } catch (\Throwable $e) {
            $this->logger->error('[Symbol Processor] Error processing symbol', [
                'symbol' => $symbol,
                'run_id' => $runId->toString(),
                'error' => $e->getMessage()
            ]);
            $this->orderJourneyLogger->error('order_journey.symbol_processor.failed', [
                'symbol' => $symbol,
                'run_id' => $runId->toString(),
                'decision_key' => $decisionKey,
                'reason' => 'exception_during_symbol_processing',
                'error' => $e->getMessage(),
            ]);

            return new SymbolResultDto(
                symbol: $symbol,
                status: 'ERROR',
                error: [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            );
        }
    }
}
