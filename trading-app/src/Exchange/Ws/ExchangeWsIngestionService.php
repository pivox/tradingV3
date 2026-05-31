<?php

declare(strict_types=1);

namespace App\Exchange\Ws;

use App\Exchange\Event\ExchangeEventBus;
use App\Exchange\Event\ExchangeEventNormalizerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ExchangeWsIngestionService
{
    public function __construct(
        private ExchangeEventNormalizerRegistry $normalizers,
        private ExchangeEventBus $bus,
        #[Autowire(service: 'monolog.logger.positions')] private LoggerInterface $logger,
    ) {
    }

    public function drain(ExchangeWsClientInterface $client, ?string $symbol = null): ExchangeWsIngestionResult
    {
        $rawEventsRead = 0;
        $eventsProjected = 0;

        foreach ($client->drainPrivateEvents($symbol) as $rawEvent) {
            ++$rawEventsRead;
            $eventsProjected += $this->bus->publishMany($this->normalizers->normalize($rawEvent));
        }

        $result = new ExchangeWsIngestionResult(
            exchange: $client->exchange(),
            marketType: $client->marketType(),
            symbol: $symbol !== null ? strtoupper($symbol) : null,
            rawEventsRead: $rawEventsRead,
            eventsProjected: $eventsProjected,
        );

        $this->logger->info('exchange_ws.ingestion_completed', [
            'exchange' => $result->exchange->value,
            'market_type' => $result->marketType->value,
            'symbol' => $result->symbol,
            'raw_events_read' => $rawEventsRead,
            'events_projected' => $eventsProjected,
        ]);

        return $result;
    }
}
