<?php

declare(strict_types=1);

namespace App\Exchange\Event;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ExchangeEventBus
{
    public function __construct(
        private ExchangeLocalProjectionStoreInterface $projectionStore,
        #[Autowire(service: 'monolog.logger.positions')] private LoggerInterface $logger,
    ) {
    }

    public function publish(ExchangeEventInterface $event): void
    {
        $this->projectionStore->project($event);
        $this->logProjected($event);
    }

    private function logProjected(ExchangeEventInterface $event): void
    {
        $this->logger->info('exchange_event.projected', [
            'event_type' => $event->eventType(),
            'exchange' => $event->exchange()->value,
            'market_type' => $event->marketType()->value,
            'symbol' => $event->symbol(),
            'occurred_at' => $event->occurredAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * @param iterable<ExchangeEventInterface> $events
     */
    public function publishMany(iterable $events): int
    {
        $batch = \is_array($events) ? array_values($events) : iterator_to_array($events, false);
        if ($batch === []) {
            return 0;
        }

        $this->projectionStore->projectAtomically($batch);
        foreach ($batch as $event) {
            $this->logProjected($event);
        }

        return \count($batch);
    }
}
