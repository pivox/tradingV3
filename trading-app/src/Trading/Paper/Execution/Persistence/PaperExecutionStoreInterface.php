<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Persistence;

use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshot;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\MarketData\PaperMarketEvent;

interface PaperExecutionStoreInterface
{
    public function registerSnapshot(PaperConfigurationSnapshot $snapshot): void;

    public function registerCell(PaperExecutionCell $cell, PaperProfileEligibility $eligibility): void;

    public function transactional(callable $operation): mixed;

    public function claimSource(PaperExecutionCell $cell, int $position, PaperMarketEvent $event): PaperSourceClaim;

    /** @param array<string, mixed> $payload */
    public function appendEffect(PaperExecutionCell $cell, int $position, string $effectKey, array $payload): void;

    /** @return list<PaperPendingEffect> */
    public function pendingEffects(PaperExecutionCell $cell): array;

    /** @param array<string, mixed> $payload */
    public function acknowledge(PaperExecutionCell $cell, int $position, string $effectKey, array $payload, int $fakeEventCursor): void;

    public function recordEffectRetry(PaperExecutionCell $cell, int $position, string $effectKey): void;

    public function recordEffectFailure(PaperExecutionCell $cell, int $position, string $effectKey, string $reason): void;

    public function checkpoint(PaperExecutionCell $cell): PaperExecutionCheckpoint;

    /** @return list<PaperMarketEvent> */
    public function acknowledgedSources(PaperExecutionCell $cell): array;

    /** @return array<string, int> */
    public function journalEventCounts(PaperExecutionCell $cell): array;

    public function kill(PaperExecutionCell $cell): void;

    public function resume(PaperExecutionCell $cell): void;
}
