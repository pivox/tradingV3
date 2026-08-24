<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution;

use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshot;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Persistence\PaperExecutionCheckpoint;
use App\Trading\Paper\Execution\Persistence\PaperExecutionCellState;
use App\Trading\Paper\Execution\Persistence\PaperExecutionStoreInterface;
use App\Trading\Paper\Execution\Persistence\PaperPendingEffect;
use App\Trading\Paper\Execution\Persistence\PaperSourceClaim;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyObservation;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final class InMemoryPaperExecutionStore implements PaperExecutionStoreInterface
{
    public int $registrationWrites = 0;
    public ?\Throwable $inspectionFailure = null;
    public ?\Throwable $checkpointFailure = null;

    /** @var array<int, PaperMarketEvent> */
    private array $sources = [];
    /** @var array<string, PaperPendingEffect> */
    private array $pending = [];
    /** @var array<int, true> */
    private array $acknowledged = [];
    private int $ordinal = 0;
    private int $cursor = 0;
    private bool $killed = false;
    private int $requested = 0;
    private int $acks = 0;
    private int $retries = 0;
    private int $failures = 0;
    /** @var array<int, PaperCanonicalStrategyObservation> */
    private array $strategyObservations = [];
    /** @var array{dataset_id: string, events_file_sha256: string, source_build_version: string|null}|null */
    private ?array $datasetIdentity = null;
    /** @var array<string, PaperProfileEligibility> */
    private array $registeredCells = [];

    /** @param list<PaperMarketEvent> $events */
    public function seedSources(array $events): void
    {
        if ($this->sources !== [] || $this->pending !== []) {
            throw new \LogicException('paper_execution_test_store_not_empty');
        }
        foreach ($events as $position => $event) {
            $this->sources[$position] = $event;
        }
        $this->ordinal = count($events);
    }

    public function registerSnapshot(PaperConfigurationSnapshot $snapshot): void { ++$this->registrationWrites; }
    public function registerCell(PaperExecutionCell $cell, PaperProfileEligibility $eligibility): void
    {
        ++$this->registrationWrites;
        $this->registeredCells[$cell->id] = $eligibility;
    }
    public function bindDataset(
        PaperExecutionCell $cell,
        string $datasetId,
        string $eventsFileSha256,
        ?string $sourceBuildVersion = null,
    ): void
    {
        if (($sourceBuildVersion !== null
                && ($sourceBuildVersion === '' || trim($sourceBuildVersion) !== $sourceBuildVersion))
            || ($cell->isModern() && $sourceBuildVersion === null)
        ) {
            throw new \InvalidArgumentException('paper_execution_dataset_identity_invalid');
        }
        ++$this->registrationWrites;
        $identity = [
            'dataset_id' => $datasetId,
            'events_file_sha256' => $eventsFileSha256,
            'source_build_version' => $sourceBuildVersion,
        ];
        if ($this->datasetIdentity !== null) {
            if ($this->datasetIdentity['dataset_id'] !== $datasetId
                || !hash_equals($this->datasetIdentity['events_file_sha256'], $eventsFileSha256)
                || ($sourceBuildVersion !== null
                    && $this->datasetIdentity['source_build_version'] !== null
                    && !hash_equals($this->datasetIdentity['source_build_version'], $sourceBuildVersion))
            ) {
                throw new \LogicException('paper_execution_dataset_identity_conflict');
            }
            if ($this->datasetIdentity['source_build_version'] === null && $sourceBuildVersion !== null) {
                $this->datasetIdentity['source_build_version'] = $sourceBuildVersion;
            }

            return;
        }
        $this->datasetIdentity = $identity;
    }
    public function inspectCell(PaperExecutionCell $cell, PaperProfileEligibility $eligibility): PaperExecutionCellState
    {
        if ($this->inspectionFailure !== null) {
            throw $this->inspectionFailure;
        }
        if (!isset($this->registeredCells[$cell->id])) {
            return PaperExecutionCellState::absent();
        }
        if ($this->registeredCells[$cell->id] !== $eligibility) {
            throw new \LogicException('paper_execution_cell_identity_conflict');
        }

        return PaperExecutionCellState::registered(
            $this->killed,
            $this->datasetIdentity['dataset_id'] ?? null,
            $this->datasetIdentity['events_file_sha256'] ?? null,
            $this->datasetIdentity['source_build_version'] ?? null,
        );
    }
    public function datasetIdentity(PaperExecutionCell $cell): array
    {
        $identity = $this->datasetIdentity
            ?? throw new \LogicException('paper_execution_dataset_identity_missing');
        if ($cell->isModern() && $identity['source_build_version'] === null) {
            throw new \LogicException('paper_execution_dataset_identity_missing');
        }

        return $identity;
    }
    public function transactional(callable $operation): mixed { return $operation(); }

    public function claimSource(PaperExecutionCell $cell, int $position, PaperMarketEvent $event): PaperSourceClaim
    {
        if ($this->killed) {
            throw new \LogicException('paper_execution_cell_killed');
        }
        if ($position < count($this->sources)) {
            $existing = $this->sources[$position];
            if ($existing->eventId !== $event->eventId || $existing->payloadHash !== $event->payloadHash) {
                throw new \LogicException('market_event_identity_conflict');
            }
            return new PaperSourceClaim(PaperSourceClaim::REPLAYED, $position, $position + 1);
        }
        if ($position > count($this->sources)) {
            throw new \LogicException('paper_execution_source_gap');
        }
        if ($this->pending !== []) {
            throw new \LogicException('paper_execution_effect_pending');
        }
        $this->sources[$position] = $event;
        ++$this->ordinal;
        return new PaperSourceClaim(PaperSourceClaim::ACCEPTED, $position, $this->ordinal);
    }

    public function appendEffect(PaperExecutionCell $cell, int $position, string $effectKey, array $payload): void
    {
        if (!isset($this->pending[$effectKey])) {
            $this->pending[$effectKey] = new PaperPendingEffect($position, $effectKey, $payload, ++$this->ordinal);
            ++$this->requested;
        }
    }

    public function appendStrategyObservation(
        PaperExecutionCell $cell,
        int $position,
        PaperCanonicalStrategyObservation $observation,
    ): void {
        if (!isset($this->sources[$position])
            || !hash_equals($cell->id, $observation->cellId)
            || !hash_equals($this->sources[$position]->eventId, $observation->sourceEventId)
        ) {
            throw new \LogicException('paper_strategy_observation_source_unclaimed');
        }
        $existing = $this->strategyObservations[$position] ?? null;
        if ($existing !== null) {
            if ($existing->toArray() !== $observation->toArray()) {
                throw new \LogicException('paper_strategy_observation_conflict');
            }

            return;
        }
        $this->strategyObservations[$position] = $observation;
        ++$this->ordinal;
    }

    /** @return list<PaperCanonicalStrategyObservation> */
    public function strategyObservations(): array
    {
        return array_values($this->strategyObservations);
    }

    public function pendingEffects(PaperExecutionCell $cell): array { return array_values($this->pending); }

    public function acknowledge(PaperExecutionCell $cell, int $position, string $effectKey, array $payload, int $fakeEventCursor): void
    {
        if (!isset($this->pending[$effectKey])) {
            return;
        }
        unset($this->pending[$effectKey]);
        $this->acknowledged[$position] = true;
        $this->cursor = $fakeEventCursor;
        ++$this->ordinal;
        ++$this->acks;
    }

    public function checkpoint(PaperExecutionCell $cell): PaperExecutionCheckpoint
    {
        if ($this->checkpointFailure !== null) {
            throw $this->checkpointFailure;
        }

        return new PaperExecutionCheckpoint($cell->id, count($this->sources), $this->ordinal, str_repeat('0', 64), $this->cursor, $this->killed, $this->ordinal);
    }

    public function kill(PaperExecutionCell $cell): void { $this->killed = true; }
    public function resume(PaperExecutionCell $cell): void { $this->killed = false; }
    public function recordEffectRetry(PaperExecutionCell $cell, int $position, string $effectKey): void { ++$this->retries; ++$this->ordinal; }
    public function recordEffectFailure(PaperExecutionCell $cell, int $position, string $effectKey, string $reason): void { ++$this->failures; ++$this->ordinal; }

    public function acknowledgedSources(PaperExecutionCell $cell): array
    {
        return array_values(array_filter(
            $this->sources,
            fn (PaperMarketEvent $event, int $position): bool => isset($this->acknowledged[$position]) || !$this->hasEffectFor($position),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    public function journalEventCounts(PaperExecutionCell $cell): array
    {
        return ['effect_requested' => $this->requested, 'effect_acknowledged' => $this->acks, 'effect_retried' => $this->retries, 'effect_failed' => $this->failures, 'strategy_observed' => count($this->strategyObservations)];
    }

    private function hasEffectFor(int $position): bool
    {
        foreach ($this->pending as $effect) {
            if ($effect->sourcePosition === $position) {
                return true;
            }
        }
        return isset($this->acknowledged[$position]);
    }
}
