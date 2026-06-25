<?php

declare(strict_types=1);

namespace App\Trading\Lineage\ReadModel;

use App\Entity\TradeLifecycleEvent;
use App\Entity\TradeLineage;

interface LineageReadStoreInterface
{
    public function count(LineageReadCriteria $criteria): int;

    /**
     * @return TradeLineage[]
     */
    public function find(LineageReadCriteria $criteria): array;

    /**
     * @return TradeLifecycleEvent[]
     */
    public function findUnmatchedEvents(LineageReadCriteria $criteria): array;

    /**
     * @return TradeLifecycleEvent[]
     */
    public function findEventsForLineage(TradeLineage $lineage, int $limit, int $offset = 0): array;

    public function countEventsForLineage(TradeLineage $lineage): int;

    public function hasCloseEventForLineage(TradeLineage $lineage): bool;
}
