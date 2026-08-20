<?php

declare(strict_types=1);

namespace App\TradingCore\Microstructure;

use App\Trading\Lineage\LineageContext;
use App\Trading\Paper\Backtesting\PaperBacktestDatasetAdapter;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final readonly class PaperMarketStateMicrostructureSnapshotProvider implements CanonicalMicrostructureSnapshotProviderInterface
{
    private CanonicalMicrostructurePolicy $policy;

    public function __construct(
        private PaperMarketStateProjector $market,
        private PaperBacktestDatasetAdapter $adapter = new PaperBacktestDatasetAdapter(),
        private CanonicalMicrostructureEngine $engine = new CanonicalMicrostructureEngine(),
    ) {
        $this->policy = new CanonicalMicrostructurePolicy(60, 2, 5, 30, 3);
    }

    public function snapshotFor(
        LineageContext $identity,
        \DateTimeImmutable $evaluatedAt,
    ): ?CanonicalMicrostructureSnapshot {
        if (!\in_array($identity->environment, ['mainnet', 'testnet'], true)
            || !\in_array($identity->exchange, ['okx', 'hyperliquid'], true)
            || $identity->marketType !== 'perpetual'
            || !\in_array($identity->symbol, ['BTCUSDT', 'ETHUSDT'], true)
        ) {
            return null;
        }

        $windowStart = $evaluatedAt->modify('-' . $this->policy->windowSeconds . ' seconds');
        $events = array_values(array_filter(
            $this->market->events(),
            static fn (PaperMarketEvent $event): bool =>
                $event->sourceNetwork->value === $identity->environment
                && $event->sourceVenue->value === $identity->exchange
                && $event->symbol === $identity->symbol
                && \in_array($event->channel, [PaperMarketDataChannel::TOP_OF_BOOK, PaperMarketDataChannel::PUBLIC_TRADE], true)
                && $event->exchangeTimestamp >= $windowStart
                && $event->exchangeTimestamp <= $evaluatedAt
                && $event->receivedTimestamp <= $evaluatedAt,
        ));
        if ($events === []) {
            return null;
        }

        $checksum = hash_init('sha256');
        foreach ($events as $event) {
            hash_update($checksum, CanonicalJson::encode($event->toArray()) . "\n");
        }
        $records = $this->adapter->adaptMicrostructureEvents(
            $events,
            'sha256:' . hash_final($checksum),
        );
        if ($records['books'] === [] || $records['trades'] === []) {
            return null;
        }

        return $this->engine->build(
            $this->policy,
            $evaluatedAt,
            $records['books'],
            $records['trades'],
        );
    }
}
