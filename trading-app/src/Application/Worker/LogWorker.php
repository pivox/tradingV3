<?php

declare(strict_types=1);

namespace App\Application\Worker;

use Temporal\Client\GRPC\ServiceClient;
use Temporal\Worker\WorkerFactory;
use Temporal\Worker\WorkerInterface;
use Psr\Log\LoggerInterface;
use App\Application\Workflow\LogProcessingWorkflow;
use App\Application\Workflow\LogProcessingWorkflowImpl;
use App\Application\Activity\LogProcessingActivity;

/**
 * Worker Temporal dédié au traitement des logs
 * Écoute les workflows de traitement des logs et exécute les activités d'écriture
 */
final class LogWorker
{
    private WorkerInterface $worker;
    private LoggerInterface $logger;

    public function __construct(
        LogProcessingActivity $logProcessingActivity,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        
        // Créer le service client
        $serviceClient = ServiceClient::create($_ENV['TEMPORAL_ADDRESS'] ?? 'temporal-grpc:7233');
        
        // Créer le worker factory
        $workerFactory = WorkerFactory::create($serviceClient);
        
        // Créer le worker
        $this->worker = $workerFactory->newWorker('log-processing-queue');
        
        // Enregistrer les workflows
        $this->worker->registerWorkflowTypes(LogProcessingWorkflowImpl::class);
        
        // Enregistrer les activités
        $this->worker->registerActivityImplementations($logProcessingActivity);
        
        $logger->info('Log Worker initialized', [
            'task_queue' => 'log-processing-queue',
            'workflows' => [LogProcessingWorkflow::class],
            'activities' => [LogProcessingActivity::class]
        ]);
    }

    public function run(): void
    {
        // Le worker Temporal se lance automatiquement
        // Cette méthode peut être utilisée pour des opérations de démarrage
        // Le worker réel est géré par Temporal
    }

    public function getWorker(): WorkerInterface
    {
        return $this->worker;
    }
}
