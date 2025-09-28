<?php

namespace App\Service\Temporal;

use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Dto\SignalPayload;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TemporalWorkflowGateway implements TemporalGatewayInterface
{
    public function __construct(
        private readonly LoggerInterface     $logger,
        private readonly HttpClientInterface $http,
        private readonly string              $temporalHttpBase = 'http://temporal:7243/api/v1'
    ) {}

    /** Démarre le workflow s'il n'est pas RUNNING (idempotent) */
    public function ensureWorkflowRunning(WorkflowRef $ref): void
    {
        // Vérifie l’état actuel
        $info = $this->http->request(
            'GET',
            sprintf('%s/namespaces/%s/workflows/%s', $this->temporalHttpBase, $ref->namespace, $ref->id)
        )->toArray(false);

        $status = $info['workflowExecutionInfo']['status'] ?? null;
        if ($status === 'WORKFLOW_EXECUTION_STATUS_RUNNING') {
            $this->logger->info(sprintf('Workflow "%s" déjà en cours', $ref->id));
            return;
        }

        $this->logger->info(sprintf('Démarrage du workflow "%s"', $ref->id));

        $payload = [
            'namespace'             => $ref->namespace,
            'workflowId'            => $ref->id,
            'workflowType'          => ['name' => $ref->type],       // ex: ApiRateLimiterClient
            'taskQueue'             => ['name' => $ref->taskQueue],  // ex: api_rate_limiter_queue
            'workflowIdReusePolicy' => 1,
            'input'                 => [],
        ];

        // 🔥 utilise /workflows/{workflowId} pour forcer l’ID
        $this->http->request(
            'POST',
            sprintf('%s/namespaces/%s/workflows/%s', $this->temporalHttpBase, $ref->namespace, $ref->id),
            ['json' => $payload]
        )->toArray(false);
    }

    /** Envoie un signal ; si NotFound/AlreadyCompleted -> SignalWithStart */
    public function sendSignal(WorkflowRef $ref, SignalPayload $signal): array
    {
        $payload = [
            'namespace'         => $ref->namespace,
            'workflowExecution' => ['workflowId' => $ref->id],
            'signalName'        => $signal->signalName,
            'input'             => $this->encodeInput([$signal->data]),
        ];

        $url = sprintf(
            '%s/namespaces/%s/workflows/%s/signal/%s',
            $this->temporalHttpBase, $ref->namespace, $ref->id, $signal->signalName
        );
        //dd($url, ['json' => $signal->data]);


        $res = $this->http->request('POST', $url, ['json' => $payload])->toArray(false);

        // Succès
        if (!isset($res['code']) || (int)$res['code'] === 0) {
            return $res;
        }

        // Fallback si NOT_FOUND / already completed (code=5)
        if ((int)($res['code'] ?? 0) === 5) {
            $sws = [
                'namespace'             => $ref->namespace,
                'workflowId'            => $ref->id,
                'workflowType'          => ['name' => $ref->type],
                'taskQueue'             => ['name' => $ref->taskQueue],
                // 1 = ALLOW_DUPLICATE (recrée une run si aucune n'est RUNNING)
                'workflowIdReusePolicy' => 1,
                'signalName'            => $signal->signalName,
                'input'                 => $this->encodeInput([$signal->data]),
            ];

            $swsUrl = sprintf(
                '%s/namespaces/%s/workflows/%s/signalWithStart/%s',
                $this->temporalHttpBase, $ref->namespace, $ref->id, $signal->signalName
            );

            return $this->http->request('POST', $swsUrl, ['json' => $sws])->toArray(false);
        }

        // Autre erreur -> renvoyer telle quelle
        return $res;
    }

    /** Encodage payloads attendu par l’API REST Temporal (base64 de JSON) */
    private function encodeInput(array $items): array
    {
        $encoded = [];
        foreach ($items as $item) {
            $encoded[] = [
                'metadata' => ['encoding' => base64_encode('json/plain')],
                'data'     => base64_encode(json_encode($item)),
            ];
        }
        return $encoded;
    }
}
