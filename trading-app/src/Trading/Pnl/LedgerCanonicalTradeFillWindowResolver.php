<?php

declare(strict_types=1);

namespace App\Trading\Pnl;

final readonly class LedgerCanonicalTradeFillWindowResolver implements CanonicalTradeFillWindowResolverInterface
{
    public function __construct(private FillQuantityAggregationProviderInterface $aggregator)
    {
    }

    public function resolve(string $internalTradeId, string $exchange, string $marketType): ?CanonicalTradeFillWindow
    {
        $aggregate = $this->aggregator->aggregateByTradeVenue($internalTradeId, $exchange, $marketType);
        if (!$aggregate->netPnlCertificationAllowed()
            || !$aggregate->entryFirstFillAt instanceof \DateTimeImmutable
            || !$aggregate->exitLastFillAt instanceof \DateTimeImmutable
            || $aggregate->entryVwap === null
        ) {
            return null;
        }

        try {
            return new CanonicalTradeFillWindow(
                $aggregate->entryFirstFillAt,
                $aggregate->exitLastFillAt,
                $aggregate->entryVwap,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
