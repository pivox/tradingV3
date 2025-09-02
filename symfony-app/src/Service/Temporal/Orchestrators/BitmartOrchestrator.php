<?php
// src/Service/Temporal/Orchestrators/BitmartOrchestrator.php
namespace App\Service\Temporal\Orchestrators;

use App\Service\Temporal\TemporalGatewayInterface;
use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Dto\SignalPayload;

final class BitmartOrchestrator
{
    public function __construct(private readonly TemporalGatewayInterface $gateway) {}

    /** Déclenche le fetch de tous les contrats */
    public function requestGetAllContracts(WorkflowRef $ref, string $baseUrl, string $callback, ?string $note = null): array
    {
        $envelope = [
            'url_type'     => 'get all contracts',
            'url_callback' => $callback,
            'base_url'     => $baseUrl,
            'payload'      => [
                'source' => 'bitmart',
                'note'   => $note ?? 'fetch contracts via callback',
            ],
        ];
        $this->gateway->ensureWorkflowRunning($ref);
        $x =  $this->gateway->sendSignal($ref, new SignalPayload('submit', $envelope));
        return $x;
    }

    /** Déclenche le fetch de klines */
    public function requestGetKlines(
        WorkflowRef $ref,
        string $baseUrl,
        string $callback,
        string $contract,
        string $timeframe = '4h',
        int $limit = 100,
        ?int $sinceTs = null
    ): array {
        $envelope = [
            'url_type'     => 'get kline',
            'url_callback' => $callback,
            'base_url'     => $baseUrl,
            'payload'      => [
                'source'    => 'bitmart',
                'contract'  => $contract,
                'timeframe' => $timeframe,
                'limit'     => $limit,
                'since_ts'  => $sinceTs,
            ],
        ];
        $this->gateway->ensureWorkflowRunning($ref);
        return $this->gateway->sendSignal($ref, new SignalPayload('submit', $envelope));
    }
}
