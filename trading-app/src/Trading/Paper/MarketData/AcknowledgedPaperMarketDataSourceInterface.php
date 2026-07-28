<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

interface AcknowledgedPaperMarketDataSourceInterface extends PaperMarketDataSourceInterface
{
    public function acknowledge(string $eventId): void;

    public function stop(): void;

    public function isComplete(): bool;
}
