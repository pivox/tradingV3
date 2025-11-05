<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\MtfValidator\Service\Application\PositionsSnapshot;
use App\MtfValidator\Service\Dto\SymbolResultDto;
use App\MtfValidator\Service\Timeframe\CascadeTimelineService;
use App\Repository\MtfSwitchRepository;
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
        private readonly CascadeTimelineService $cascadeTimelineService,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly MtfSwitchRepository $switchRepository,
        #[Autowire(service: 'monolog.logger.order_journey')] private readonly LoggerInterface $orderJourneyLogger,
    ) {}

    /**
     * Traite un symbole individuel
     */
    public function processSymbol(
        string $symbol,
        UuidInterface $runId,
        MtfRunDto $mtfRunDto,
        \DateTimeImmutable $now,
        PositionsSnapshot $snapshot
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
            $symbolContext = $snapshot->getSymbolContext($symbol);
            $overrideTf = $this->resolveTimeframeOverride($symbol, $mtfRunDto, $symbolContext);

            $result = $this->cascadeTimelineService->execute(
                $symbol,
                $runId,
                $mtfRunDto,
                $now,
                $overrideTf,
                $symbolContext
            );

            $this->orderJourneyLogger->info('order_journey.symbol_processor.completed', [
                'symbol' => $symbol,
                'run_id' => $runId->toString(),
                'decision_key' => $decisionKey,
                'status' => $result->status,
                'execution_tf' => $result->executionTf,
                'signal_side' => $result->signalSide,
                'override_tf' => $overrideTf,
                'reason' => 'mtf_symbol_processing_end',
            ]);

            return $result;

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

    private function resolveTimeframeOverride(string $symbol, MtfRunDto $dto, array $symbolContext): ?string
    {
        if ($dto->currentTf !== null) {
            return $dto->currentTf;
        }

        if (!$this->switchRepository->isSymbolSwitchOn($symbol)) {
            return '15m';
        }

        if (!$this->switchRepository->isSymbolTimeframeSwitchOn($symbol, '1m')) {
            return '1m';
        }

        $ordersCount = 0;
        if (isset($symbolContext['orders']) && is_iterable($symbolContext['orders'])) {
            if (is_array($symbolContext['orders']) || $symbolContext['orders'] instanceof \Countable) {
                $ordersCount = count($symbolContext['orders']);
            } else {
                foreach ($symbolContext['orders'] as $_) {
                    $ordersCount++;
                }
            }
        }

        $adjustmentRequested = (bool)($symbolContext['adjustment_requested'] ?? false);

        if ($adjustmentRequested) {
            return '1m';
        }

        if ($ordersCount > 0) {
            return '15m';
        }

        return null;
    }
}
