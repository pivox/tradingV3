<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Persistence;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketEvent;

/**
 * Single-writer replay sessions: the cell journal and checkpoint are kept in memory and
 * committed in batches, together with every other write of the batch (order intents,
 * exchange projections) and a durable fake exchange snapshot recorded in the same commit.
 */
interface PaperReplayBatchingStoreInterface
{
    /**
     * Opens the session (one database transaction per batch) for a registered cell.
     *
     * @param \Closure(): iterable<PaperMarketEvent> $replayedPrefix the already consumed
     *        source events (positions 0..next-1, in replay order), used to rebuild market state
     *
     * @return array{next_source_position: int, snapshot: array{journal_ordinal: int, sha256: string, bytes: int}|null}
     */
    public function beginReplayBatch(PaperExecutionCell $cell, \Closure $replayedPrefix): array;

    public function replayBatchActive(): bool;

    /** Whether the market effect at a position changed any non market-data fake exchange state. */
    public function recordMarketEffectMateriality(
        PaperExecutionCell $cell,
        int $sourcePosition,
        string $effectKey,
        bool $material,
    ): void;

    /** Number of source positions consumed since the last commit. */
    public function pendingReplayBatchSize(): int;

    /**
     * Commits the batch. $writeSnapshot receives the journal ordinal of the snapshot row and
     * must durably write the fake exchange state before returning its digest; it runs before
     * the commit, so a crash on either side never pairs a journal with another state.
     *
     * @param \Closure(int): array{sha256: string, bytes: int, state_revision: int} $writeSnapshot
     *
     * @return array{journal_ordinal: int, journal_checksum: string, next_source_position: int, fake_event_cursor: int, snapshot_sha256: string, snapshot_bytes: int}
     */
    public function flushReplayBatch(\Closure $writeSnapshot): array;

    /** Ends a session whose last batch was committed. */
    public function endReplayBatch(): void;

    /** Rolls the open batch back and ends the session. */
    public function abortReplayBatch(): void;

    /** @return array{position: int, event_id: string, exchange_timestamp: string}|null */
    public function lastSourceIdentity(PaperExecutionCell $cell): ?array;
}
