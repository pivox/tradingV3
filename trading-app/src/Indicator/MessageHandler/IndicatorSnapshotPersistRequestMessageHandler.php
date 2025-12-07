<?php

declare(strict_types=1);

namespace App\Indicator\MessageHandler;

use App\Contract\Indicator\IndicatorProviderInterface;
use App\Indicator\Message\IndicatorSnapshotPersistRequestMessage;
use App\Indicator\Message\IndicatorSnapshotProjectionMessage;
use App\Indicator\Service\IndicatorSnapshotProjector;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class IndicatorSnapshotPersistRequestMessageHandler
{
    public function __construct(
        private readonly IndicatorProviderInterface $indicatorProvider,
        private readonly IndicatorSnapshotProjector $projector,
        #[Autowire(service: 'monolog.logger.indicators')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(IndicatorSnapshotPersistRequestMessage $message): void
    {
        $symbols = array_values(array_unique(array_filter(array_map('strtoupper', $message->symbols))));
        $timeframes = array_values(array_unique(array_filter(array_map('strtolower', $message->timeframes))));

        if ($symbols === [] || $timeframes === []) {
            return;
        }

        $referenceTime = $this->resolveReferenceTime($message->requestedAt);

        foreach ($symbols as $symbol) {
            try {
                $indicatorSets = $this->indicatorProvider->getIndicatorsForSymbolAndTimeframes(
                    $symbol,
                    $timeframes,
                    $referenceTime,
                );
            } catch (\Throwable $exception) {
                $this->logger->warning('[IndicatorPersistence] Failed to fetch indicators', [
                    'symbol' => $symbol,
                    'timeframes' => $timeframes,
                    'error' => $exception->getMessage(),
                ]);
                continue;
            }

            foreach ($indicatorSets as $tf => $values) {
                if (!is_array($values) || $values === []) {
                    continue;
                }

                $klineTime = $this->extractKlineTime($values, $referenceTime);
                unset($values['kline_time']);

                try {
                    $this->projector->project(new IndicatorSnapshotProjectionMessage(
                        $symbol,
                        (string) $tf,
                        $klineTime,
                        $values,
                        'MTF_RUNNER',
                        $message->runId,
                    ));
                } catch (\Throwable $exception) {
                    $this->logger->warning('[IndicatorPersistence] Projection failed', [
                        'symbol' => $symbol,
                        'timeframe' => $tf,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }

    private function resolveReferenceTime(?string $requestedAt): \DateTimeImmutable
    {
        if (is_string($requestedAt) && $requestedAt !== '') {
            try {
                return (new \DateTimeImmutable($requestedAt, new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Throwable) {
                // fall through to now
            }
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string,mixed> $values
     */
    private function extractKlineTime(array $values, \DateTimeImmutable $fallback): string
    {
        $raw = $values['kline_time'] ?? null;
        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        return $fallback->format('Y-m-d H:i:s');
    }
}
