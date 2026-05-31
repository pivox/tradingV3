<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Ws\ExchangeWsClientInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exchange_ws_client')]
final class FakeExchangeWsClient implements ExchangeWsClientInterface
{
    /** @var array<string,bool> */
    private array $consumedSequences = [];

    public function __construct(
        private readonly FakeExchangeStateStore $stateStore,
    ) {
    }

    public function exchange(): Exchange
    {
        return Exchange::FAKE;
    }

    public function marketType(): MarketType
    {
        return MarketType::PERPETUAL;
    }

    /**
     * @return FakeExchangeEvent[]
     */
    public function drainPrivateEvents(?string $symbol = null): iterable
    {
        $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;
        $events = [];
        foreach ($this->stateStore->events() as $index => $event) {
            $sequence = $this->sequence($event, $index);
            if (isset($this->consumedSequences[$sequence])) {
                continue;
            }
            if ($normalizedSymbol !== null && $event->symbol !== $normalizedSymbol) {
                continue;
            }

            $this->consumedSequences[$sequence] = true;
            $events[] = $event;
        }

        return $events;
    }

    private function sequence(FakeExchangeEvent $event, int $index): string
    {
        $sequence = $event->payload['event_sequence'] ?? null;

        return \is_scalar($sequence) ? (string)$sequence : 'idx-' . $index;
    }
}
