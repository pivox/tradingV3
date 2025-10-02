<?php

declare(strict_types=1);

namespace App\Service\Bitmart;

use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Orchestrators\BitmartOrchestrator;
use App\Util\TimeframeHelper;

final class BitmartRefreshService
{
    private const BASE_URL = 'http://nginx';
    private const CALLBACK = 'api/callback/bitmart/get-kline';
    private const LIMIT_KLINES = 260;

    public function __construct(private readonly BitmartOrchestrator $bitmartOrchestrator)
    {
    }

    public function refreshSingle(string $symbol, string $timeframe, ?int $limit = null): void
    {
        $limit = $limit ?? self::LIMIT_KLINES;
        $tfMinutes = TimeframeHelper::parseTimeframeToMinutes($timeframe);
        $cutoff = TimeframeHelper::getAlignedOpenByMinutes($tfMinutes);
        $start = $cutoff->modify('-' . ($limit - 1) * $tfMinutes . ' minutes');

        $workflowRef = new WorkflowRef('api-rate-limiter-workflow', 'ApiRateLimiterClient', 'api_rate_limiter_queue');
        $batchId = sprintf('refresh-%s-%s', strtolower($symbol), (new \DateTimeImmutable())->format('YmdHisv'));
        $this->bitmartOrchestrator->reset();
        $this->bitmartOrchestrator->setWorkflowRef($workflowRef);

        $this->bitmartOrchestrator->requestGetKlines(
            $workflowRef,
            baseUrl: self::BASE_URL,
            callback: self::CALLBACK,
            contract: $symbol,
            timeframe: $timeframe,
            limit: $limit,
            start: $start,
            end: $cutoff,
            note: 'auto-refresh',
            batchId: $batchId
        );

        $this->bitmartOrchestrator->go();
    }
}

