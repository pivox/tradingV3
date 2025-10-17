<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\MtfRunCompletedEvent;
use App\Service\Indicator\SqlIndicatorService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Listener pour rafraîchir automatiquement les vues matérialisées
 * après la completion d'un cycle MTF
 */
#[AsEventListener(event: 'mtf.run.completed', priority: 100)]
class MtfRunCompletedListener
{
    public function __construct(
        private readonly SqlIndicatorService $sqlIndicatorService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Rafraîchit les vues matérialisées après la completion d'un cycle MTF
     */
    public function onMtfRunCompleted(MtfRunCompletedEvent $event): void
    {
        try {
            $this->logger->info('[MTF Listener] Starting materialized views refresh', [
                'run_id' => $event->getRunId(),
                'symbols_count' => $event->getSymbolsCount(),
                'execution_time' => $event->getExecutionTime(),
            ]);

            $this->sqlIndicatorService->refreshMaterializedViews();

            $this->logger->info('[MTF Listener] Materialized views refreshed successfully', [
                'run_id' => $event->getRunId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Listener] Failed to refresh materialized views', [
                'run_id' => $event->getRunId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
