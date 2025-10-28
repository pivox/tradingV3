<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\MtfValidator\Event\MtfRunCompletedEvent;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Service pour gérer les événements MTF
 */
class MtfEventService
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Déclenche le rafraîchissement des vues matérialisées
     */
    public function triggerMaterializedViewsRefresh(string $runId, array $context = []): void
    {
        try {
            $this->logger->info('[MTF Event Service] Triggering materialized views refresh', [
                'run_id' => $runId,
                'context' => $context,
            ]);

            // Les vues matérialisées ne sont plus utilisées

            $this->logger->info('[MTF Event Service] Materialized views refresh completed', [
                'run_id' => $runId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Event Service] Failed to refresh materialized views', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
                'context' => $context,
            ]);
        }
    }

    /**
     * Déclenche un événement MTF personnalisé
     */
    public function dispatchMtfEvent(string $eventName, array $data = []): void
    {
        try {
            $runId = isset($data['run_id']) && $data['run_id'] !== 'unknown'
                ? Uuid::fromString($data['run_id'])
                : Uuid::uuid4();

            $this->eventDispatcher->dispatch(new MtfRunCompletedEvent(
                $runId,
                $data['symbols'] ?? [],
                $data['symbols_count'] ?? 0,
                $data['execution_time'] ?? 0.0,
                $data['summary'] ?? [],
                $data['results'] ?? []
            ), $eventName);

            $this->logger->info('[MTF Event Service] Custom event dispatched', [
                'event_name' => $eventName,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[MTF Event Service] Failed to dispatch custom event', [
                'event_name' => $eventName,
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
        }
    }
}
