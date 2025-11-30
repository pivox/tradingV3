<?php

declare(strict_types=1);

namespace App\Indicator\Service;

use App\Common\Enum\Timeframe;
use App\Entity\IndicatorSnapshot;
use App\Indicator\Message\IndicatorSnapshotProjectionMessage;
use App\Logging\TraceIdProvider;
use App\Repository\IndicatorSnapshotRepository;
use Psr\Log\LoggerInterface;

final class IndicatorSnapshotProjector
{
    public function __construct(
        private readonly IndicatorSnapshotRepository $snapshotRepository,
        private readonly TraceIdProvider $traceIdProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function project(IndicatorSnapshotProjectionMessage $message): void
    {
        $timeframe = Timeframe::from($message->timeframe);
        $klineTime = new \DateTimeImmutable($message->klineTime, new \DateTimeZone('UTC'));
        $traceId = $this->traceIdProvider->getOrCreate($message->symbol);

        $snapshot = (new IndicatorSnapshot())
            ->setSymbol(strtoupper($message->symbol))
            ->setTimeframe($timeframe)
            ->setKlineTime($klineTime->setTimezone(new \DateTimeZone('UTC')))
            ->setRunId($message->runId)
            ->setTraceId($traceId)
            ->setSource($message->source)
            ->setValues($message->values)
            ->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $this->snapshotRepository->upsert($snapshot);

        $this->logger->info('[IndicatorSnapshotProjector] Snapshot persisted', [
            'symbol' => strtoupper($message->symbol),
            'timeframe' => $message->timeframe,
            'kline_time' => $message->klineTime,
            'values_count' => count($message->values),
        ]);
    }
}
