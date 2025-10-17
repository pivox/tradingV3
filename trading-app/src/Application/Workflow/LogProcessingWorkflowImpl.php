<?php

declare(strict_types=1);

namespace App\Application\Workflow;

use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;
use Temporal\Workflow\SignalMethod;
use Temporal\Workflow\QueryMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Workflow;
use App\Application\Activity\LogProcessingActivity;

/**
 * Implémentation du workflow de traitement des logs
 */
#[WorkflowInterface]
final class LogProcessingWorkflowImpl implements LogProcessingWorkflow
{
    private array $pendingLogs = [];
    private int $processedCount = 0;
    private int $errorCount = 0;
    private bool $flushRequested = false;
    private $logProcessingActivity;

    public function __construct()
    {
        // Configuration des activités avec retry
        $this->logProcessingActivity = Workflow::newActivityStub(
            LogProcessingActivity::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout('30s')
                ->withRetryOptions(
                    Workflow::newRetryOptions()
                        ->withInitialInterval('1s')
                        ->withMaximumInterval('30s')
                        ->withBackoffCoefficient(2.0)
                        ->withMaximumAttempts(3)
                )
        );
    }

    #[WorkflowMethod]
    public function processLog(
        string $channel,
        string $level,
        string $message,
        array $context = [],
        ?string $symbol = null,
        ?string $timeframe = null,
        ?string $side = null
    ): \Generator {
        $log = [
            'channel' => $channel,
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'side' => $side,
            'timestamp' => Workflow::now()->format('Y-m-d H:i:s.v'),
        ];

        $this->pendingLogs[] = $log;
        yield from $this->processPendingLogs();
    }

    #[WorkflowMethod]
    public function processLogBatch(array $logs, bool $isBatch = false): \Generator
    {
        if ($isBatch) {
            // Traitement en batch optimisé
            $this->pendingLogs = array_merge($this->pendingLogs, $logs);
            yield from $this->processPendingLogs();
        } else {
            // Traitement log par log
            foreach ($logs as $log) {
                $this->processLog(
                    $log['channel'],
                    $log['level'],
                    $log['message'],
                    $log['context'] ?? [],
                    $log['symbol'] ?? null,
                    $log['timeframe'] ?? null,
                    $log['side'] ?? null
                );
            }
        }
    }

    #[SignalMethod]
    public function flushLogs(): \Generator
    {
        $this->flushRequested = true;
        yield from $this->processPendingLogs();
    }

    #[QueryMethod]
    public function getStatus(): array
    {
        return [
            'pending_logs' => count($this->pendingLogs),
            'processed_count' => $this->processedCount,
            'error_count' => $this->errorCount,
            'flush_requested' => $this->flushRequested,
        ];
    }

    private function processPendingLogs(): \Generator
    {
        if (empty($this->pendingLogs)) {
            return;
        }

        // Traiter par batch de 50 logs maximum
        $batchSize = 50;
        $batches = array_chunk($this->pendingLogs, $batchSize);

        foreach ($batches as $batch) {
            try {
                // Appeler l'activité de traitement
                $this->logProcessingActivity->writeLogBatch($batch);
                
                $this->processedCount += count($batch);
                
                // Attendre un court délai entre les batches
                yield Workflow::timer('100ms');
                
            } catch (\Exception $e) {
                $this->errorCount += count($batch);
                
                // En cas d'erreur, essayer de traiter les logs individuellement
                foreach ($batch as $log) {
                    try {
                        $this->logProcessingActivity->writeLog(
                            $log['channel'],
                            $log['level'],
                            $log['message'],
                            $log['context'] ?? [],
                            $log['symbol'] ?? null,
                            $log['timeframe'] ?? null,
                            $log['side'] ?? null
                        );
                        $this->processedCount++;
                    } catch (\Exception $individualError) {
                        $this->errorCount++;
                    }
                }
            }
        }

        // Vider les logs traités
        $this->pendingLogs = [];
        $this->flushRequested = false;
    }
}
