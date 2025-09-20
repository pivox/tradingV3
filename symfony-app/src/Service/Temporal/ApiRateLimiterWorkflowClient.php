<?php

declare(strict_types=1);

namespace App\Service\Temporal;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client minimal pour signaler le workflow Temporal "ApiRateLimiterClient"
 * via l’HTTP API (ENABLE_HTTP_API=true, host: temporal:8080).
 */
final class ApiRateLimiterWorkflowClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $temporalHttpBase = 'http://temporal:8080',
        private readonly string $namespace = 'default',
        private readonly string $signalName = 'submit', // défini dans ton workflow Python
    ) {}

    /**
     * Envoie un signal "submit" avec un enveloppe JSON.
     * @param array<string,mixed> $envelope
     */
    public function submit(string $workflowId, array $envelope): string
    {
        $requestId = $envelope['request_id'] ?? bin2hex(random_bytes(8));
        $payload   = ['input' => [$envelope + ['request_id' => $requestId]]];

        // HTTP API Temporal OSS (>= v1.22) :
        // POST /api/v1/namespaces/{ns}/workflows/{workflowId}/signal/{signalName}
        $url = sprintf(
            '%s/api/v1/namespaces/%s/workflows/%s/signal/%s',
            rtrim($this->temporalHttpBase, '/'),
            rawurlencode($this->namespace),
            rawurlencode($workflowId),
            rawurlencode($this->signalName),
        );

        $this->http->request('POST', $url, ['json' => $payload, 'timeout' => 10]);

        return $requestId;
    }
}
