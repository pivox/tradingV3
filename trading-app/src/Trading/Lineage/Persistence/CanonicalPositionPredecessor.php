<?php

declare(strict_types=1);

namespace App\Trading\Lineage\Persistence;

use App\Entity\FuturesOrder;
use App\Entity\FuturesOrderTrade;
use App\Trading\Lineage\LineageContext;

final readonly class CanonicalPositionPredecessor
{
    public function __construct(
        public FuturesOrder $order,
        public ?FuturesOrderTrade $fill,
        public string $exchangePositionId,
        public LineageContext $context,
    ) {}
}
