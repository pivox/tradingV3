<?php

declare(strict_types=1);

namespace App\Service\Temporal;

use App\Service\Pipeline\TemporalDispatcherInterface;
use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Orchestrators\BitmartOrchestrator;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

final class TemporalDispatcher implements TemporalDispatcherInterface
{
    public function __construct(
        private readonly BitmartOrchestrator $orchestrator,
        private readonly WorkflowRef $workflowRef,
        private readonly string $baseUrl,
        private readonly string $callbackPath,
        private readonly int $defaultLimit,
        private readonly LoggerInterface $logger,
    ) {}

    public function dispatchEvaluate(string $symbol, string $tf, DateTimeImmutable $slot, array $headers = []): void
    {
        $limit = (int)($headers['limit'] ?? $this->defaultLimit);
        $meta = $headers['meta'] ?? [];
        $note = $headers['note'] ?? sprintf('pipeline %s', $tf);
        $batchId = $headers['batch_id'] ?? sprintf('mtf-%s-%s', $tf, $slot->format('YmdHis'));

        $this->orchestrator->requestGetKlines(
            $this->workflowRef,
            $this->baseUrl,
            $this->callbackPath,
            $symbol,
            $tf,
            $limit,
            null,
            null,
            $note,
            $batchId,
            $meta
        );
        $this->logger->info('[temporal-dispatch] scheduled evaluation', [
            'symbol' => $symbol,
            'tf' => $tf,
            'limit' => $limit,
            'batch_id' => $batchId,
        ]);
        $this->orchestrator->go();
    }
}
