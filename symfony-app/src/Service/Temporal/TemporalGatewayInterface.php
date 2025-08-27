<?php
// src/Service/Temporal/TemporalGatewayInterface.php
namespace App\Service\Temporal;

use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Dto\SignalPayload;

interface TemporalGatewayInterface
{
    /** Vérifie et démarre le workflow s’il n’est pas RUNNING (idempotent) */
    public function ensureWorkflowRunning(WorkflowRef $ref): void;

    /** Envoie un signal JSON (encodage base64/JSON conforme à l’API REST Temporal) */
    public function sendSignal(WorkflowRef $ref, SignalPayload $signal): array;
}
