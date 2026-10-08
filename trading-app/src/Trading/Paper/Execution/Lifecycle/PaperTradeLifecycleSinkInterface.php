<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Lifecycle;

use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;

/**
 * Trade lifecycle of the Paper projection (#132 j): what live trading records in
 * trade_lifecycle_event for an order and its position, written in the transaction that projects
 * the Fake exchange events, so that a crash and a resume can neither lose nor duplicate it.
 */
interface PaperTradeLifecycleSinkInterface
{
    /** The projection store to use with this lifecycle, or null to keep the coordinator's own. */
    public function projection(): ?ExchangeLocalProjectionStoreInterface;

    /**
     * Called once per projected batch, right after it was projected, in the same transaction.
     *
     * @param list<ExchangeEventInterface> $events the batch, in projection order
     */
    public function afterProjection(PaperExecutionCell $cell, array $events, ?PaperLifecycleSubmission $submission): void;
}
