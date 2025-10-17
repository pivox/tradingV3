<?php

declare(strict_types=1);

namespace App\Logging;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP pour Temporal - Logging
 * Utilise l'API HTTP de Temporal pour publier des logs
 */
final class TemporalHttpClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $temporalHttpBase = 'http://temporal:7243',
        private readonly string $namespace = 'default',
    ) {}

    /**
     * Publie un log via l'API HTTP de Temporal
     */
    public function publishLog(array $logData): string
    {
        $workflowId = $this->generateWorkflowId($logData['channel'], $logData['level']);
        
        // Créer le payload pour le workflow (format Temporal)
        $payload = [
            'namespace' => $this->namespace,
            'workflowId' => $workflowId,
            'workflowType' => ['name' => 'LogProcessingWorkflow'],
            'taskQueue' => ['name' => 'log-processing-queue'],
            'workflowIdReusePolicy' => 1, // ALLOW_DUPLICATE
            'input' => $this->encodeInput([$logData])
        ];

        // URL pour démarrer un workflow
        $url = sprintf(
            '%s/api/v1/namespaces/%s/workflows/%s',
            rtrim($this->temporalHttpBase, '/'),
            rawurlencode($this->namespace),
            rawurlencode($workflowId)
        );

        $response = $this->http->request('POST', $url, [
            'json' => $payload,
            'timeout' => 10
        ]);

        return $workflowId;
    }

    /**
     * Publie un batch de logs
     */
    public function publishLogBatch(array $logs): string
    {
        $workflowId = 'log-batch-' . uniqid();
        
        // Créer le payload pour le workflow (format Temporal)
        $payload = [
            'namespace' => $this->namespace,
            'workflowId' => $workflowId,
            'workflowType' => ['name' => 'LogProcessingWorkflow'],
            'taskQueue' => ['name' => 'log-processing-queue'],
            'workflowIdReusePolicy' => 1, // ALLOW_DUPLICATE
            'input' => $this->encodeInput([$logs, true]) // true = isBatch
        ];

        $url = sprintf(
            '%s/api/v1/namespaces/%s/workflows/%s',
            rtrim($this->temporalHttpBase, '/'),
            rawurlencode($this->namespace),
            rawurlencode($workflowId)
        );

        $response = $this->http->request('POST', $url, [
            'json' => $payload,
            'timeout' => 10
        ]);

        return $workflowId;
    }

    private function generateWorkflowId(string $channel, string $level): string
    {
        return sprintf('log-%s-%s-%s', $channel, $level, uniqid());
    }

    /**
     * Encodage payloads attendu par l'API REST Temporal (base64 de JSON)
     */
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