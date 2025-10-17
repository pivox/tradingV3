<?php

declare(strict_types=1);

namespace App\Application\Workflow;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;
use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\QueryMethod;
use App\Application\Activity\LogProcessingActivity;

/**
 * Workflow Temporal pour le traitement asynchrone des logs
 * Gère l'écriture sur filesystem avec retry et monitoring
 */
#[WorkflowInterface]
interface LogProcessingWorkflow
{
    /**
     * Traite un log unique
     */
    #[WorkflowMethod]
    public function processLog(
        string $channel,
        string $level,
        string $message,
        array $context = [],
        ?string $symbol = null,
        ?string $timeframe = null,
        ?string $side = null
    ): \Generator;

    /**
     * Traite un batch de logs
     */
    #[WorkflowMethod]
    public function processLogBatch(array $logs, bool $isBatch = false): \Generator;

    /**
     * Signal pour forcer le flush des logs en attente
     */
    #[SignalMethod]
    public function flushLogs(): \Generator;

    /**
     * Query pour obtenir le statut du workflow
     */
    #[QueryMethod]
    public function getStatus(): array;
}
