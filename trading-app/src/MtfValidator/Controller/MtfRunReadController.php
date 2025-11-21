<?php

declare(strict_types=1);

namespace App\MtfValidator\Controller;

use App\MtfValidator\Repository\{MtfRunRepository, MtfRunSymbolRepository, MtfRunMetricRepository};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/mtf', name: 'mtf_')]
final class MtfRunReadController extends AbstractController
{
    public function __construct(
        private readonly MtfRunRepository $runRepo,
        private readonly MtfRunSymbolRepository $symbolRepo,
        private readonly MtfRunMetricRepository $metricRepo,
        private readonly LoggerInterface $mtfLogger,
    ) {}

    #[Route('/runs/{runId}', name: 'get_run', methods: ['GET'])]
    public function getRun(string $runId): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($runId);
        } catch (\Throwable) {
            return $this->json([
                'status' => 'error',
                'message' => 'Invalid run_id format',
            ], 400);
        }

        try {
            $run = $this->runRepo->find($uuid);
            if ($run === null) {
                return $this->json([
                    'status' => 'error',
                    'message' => 'Run not found',
                ], 404);
            }

            // Build run summary payload (keys align with existing API where possible)
            $summary = [
                'run_id' => $run->getRunId()->toString(),
                'status' => $run->getStatus(),
                'execution_time_seconds' => $run->getExecutionTimeSeconds(),
                'symbols_requested' => $run->getSymbolsRequested(),
                'symbols_processed' => $run->getSymbolsProcessed(),
                'symbols_successful' => $run->getSymbolsSuccessful(),
                'symbols_failed' => $run->getSymbolsFailed(),
                'symbols_skipped' => $run->getSymbolsSkipped(),
                'success_rate' => $run->getSuccessRate(),
                'dry_run' => $run->isDryRun(),
                'force_run' => $run->isForceRun(),
                'current_tf' => $run->getCurrentTf(),
                'started_at' => $run->getStartedAt()->format('Y-m-d H:i:s'),
                'finished_at' => $run->getFinishedAt()?->format('Y-m-d H:i:s'),
            ];

            // Symbols map
            $symbolsOut = [];
            foreach ($this->symbolRepo->findBy(['run' => $run]) as $row) {
                $symbol = $row->getSymbol();
                $symbolsOut[$symbol] = [
                    'symbol' => $symbol,
                    'status' => $row->getStatus(),
                    'execution_tf' => $row->getExecutionTf(),
                    'blocking_tf' => $row->getBlockingTf(),
                    'signal_side' => $row->getSignalSide(),
                    'current_price' => ($row->getCurrentPrice() !== null) ? (float) $row->getCurrentPrice() : null,
                    'atr' => ($row->getAtr() !== null) ? (float) $row->getAtr() : null,
                    'validation_mode_used' => $row->getValidationModeUsed(),
                    'trade_entry_mode_used' => $row->getTradeEntryModeUsed(),
                    'trading_decision' => $row->getTradingDecision(),
                    'error' => $row->getError(),
                    'context' => $row->getContext(),
                ];
            }

            // Metrics list
            $metrics = [];
            foreach ($this->metricRepo->findBy(['run' => $run]) as $m) {
                $metrics[] = [
                    'category' => $m->getCategory(),
                    'operation' => $m->getOperation(),
                    'symbol' => $m->getSymbol(),
                    'timeframe' => $m->getTimeframe(),
                    'count' => $m->getCount(),
                    'duration' => $m->getDuration(),
                ];
            }

            return $this->json([
                'status' => 'success',
                'data' => [
                    'run' => $summary,
                    'symbols' => $symbolsOut,
                    'metrics' => $metrics,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->mtfLogger->error('[MTF RunRead] Failed to fetch run', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
            return $this->json([
                'status' => 'error',
                'message' => 'Failed to fetch run',
            ], 500);
        }
    }
}
