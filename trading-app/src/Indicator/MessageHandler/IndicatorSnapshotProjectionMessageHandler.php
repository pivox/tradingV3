<?php

declare(strict_types=1);

namespace App\Indicator\MessageHandler;

use App\Indicator\Message\IndicatorSnapshotProjectionMessage;
use App\Indicator\Service\IndicatorSnapshotProjector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class IndicatorSnapshotProjectionMessageHandler
{
    public function __construct(
        private readonly IndicatorSnapshotProjector $projector,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(IndicatorSnapshotProjectionMessage $message): void
    {
        try {
            $this->projector->project($message);
        } catch (\Throwable $exception) {
            $this->logger->error('[IndicatorSnapshot] Failed to project snapshot', [
                'symbol' => $message->symbol,
                'timeframe' => $message->timeframe,
                'kline_time' => $message->klineTime,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }
}
