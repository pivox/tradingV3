<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Persistence;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyObservation;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Doctrine\DBAL\Connection;

/**
 * In-memory journal of one replay batch for one cell (single writer).
 *
 * It answers the same questions and enforces the same invariants as the database journal
 * (claim order, pending effects, acknowledgement conflicts, kill flag) and, at commit,
 * writes the batch with this format:
 *
 * - a source position whose only effect is a market effect that left every non market-data
 *   part of the fake exchange unchanged (no order, fill, position, balance, funding, fault or
 *   event change: only book tops / mark prices moved) is "pure": consecutive pure positions are
 *   folded into one `replay_batch` row holding their count, the chained digest of their source
 *   identities, the run-length encoded strategy observations and the last source identity;
 * - every other position keeps exactly its former rows (source_claimed, strategy_observed,
 *   effect_requested, effect_acknowledged, effect_retried, effect_failed) and payloads;
 * - each commit ends with a `fake_state_snapshot` row: digest of the durable fake exchange
 *   state written just before the commit, for the same next source position and cursor.
 *
 * @internal
 */
final class PaperReplayBatchJournal
{
    public const BATCH_EVENT_TYPE = 'replay_batch';
    public const SNAPSHOT_EVENT_TYPE = 'fake_state_snapshot';
    public const BATCH_SCHEMA = 'paper-replay-batch.v1';
    public const SNAPSHOT_SCHEMA = 'paper-fake-state-snapshot.v1';
    private const INSERT_CHUNK_ROWS = 200;

    /** @var list<array{type: string, position: int|null, event_id: string|null, effect_key: string|null, payload: array<string, mixed>}> */
    private array $entries = [];

    /** @var array<int, true> */
    private array $materialPositions = [];

    /** @var array<string, array{position: int, payload: array<string, mixed>, provisional: int}> */
    private array $pending = [];

    /** @var array<string, array{position: int, checksum: string}> effect key => request, this batch */
    private array $requested = [];

    /** @var array<string, string> effect key => acknowledgement checksum, this batch */
    private array $acknowledged = [];

    /** @var array<int, string> position => observation checksum, this batch */
    private array $observations = [];

    /** @var array{position: int, event_id: string, exchange_timestamp: string}|null */
    private ?array $lastClaim = null;

    private int $flushedNext;

    private int $flushedCursor;

    /**
     * @param \Closure(string, string, int, string, ?int, ?string, ?string, array<string, mixed>, string): string $chain
     * @param \Closure(): iterable<PaperMarketEvent> $replayedPrefix
     * @param array{dataset_id: string, events_file_sha256: string, source_build_version: string|null} $datasetIdentity
     */
    public function __construct(
        private readonly Connection $connection,
        public readonly PaperExecutionCell $cell,
        private readonly \Closure $chain,
        public readonly \Closure $replayedPrefix,
        public readonly array $datasetIdentity,
        private int $next,
        private int $ordinal,
        private string $checksum,
        private int $cursor,
        private readonly bool $killed,
    ) {
        $this->flushedNext = $next;
        $this->flushedCursor = $cursor;
    }

    public function owns(PaperExecutionCell $cell): bool
    {
        return hash_equals($this->cell->id, $cell->id);
    }

    public function checkpoint(): PaperExecutionCheckpoint
    {
        return new PaperExecutionCheckpoint(
            cellId: $this->cell->id,
            nextSourcePosition: $this->next,
            journalOrdinal: $this->ordinal,
            journalChecksum: $this->checksum,
            fakeEventCursor: $this->cursor,
            killed: $this->killed,
            lockVersion: $this->ordinal,
        );
    }

    public function size(): int
    {
        return $this->next - $this->flushedNext;
    }

    public function claimSource(int $position, PaperMarketEvent $event): PaperSourceClaim
    {
        if ($position < 0) {
            throw new \InvalidArgumentException('paper_execution_source_position_invalid');
        }
        if ($position < $this->next) {
            // Replayed positions are never fed inside a batch session: the reader resumes after
            // the committed checkpoint and pending effects never survive a commit.
            throw new \LogicException('paper_execution_batched_source_replay_unsupported');
        }
        if ($position > $this->next) {
            throw new \LogicException('paper_execution_source_gap');
        }
        if ($this->next > 0 && $this->hasPendingAt($this->next - 1)) {
            throw new \LogicException('paper_execution_effect_pending');
        }
        if ($this->killed) {
            throw new \LogicException('paper_execution_cell_killed');
        }

        $this->append('source_claimed', $event->toArray(), $position, $event->eventId, null);
        $this->lastClaim = [
            'position' => $position,
            'event_id' => $event->eventId,
            'exchange_timestamp' => $event->exchangeTimestamp->format('Y-m-d\\TH:i:s.u\\Z'),
        ];
        ++$this->next;

        return new PaperSourceClaim(PaperSourceClaim::ACCEPTED, $position, $this->provisionalOrdinal());
    }

    public function appendStrategyObservation(int $position, PaperCanonicalStrategyObservation $observation): void
    {
        if ($this->lastClaim === null
            || $this->lastClaim['position'] !== $position
            || !hash_equals($this->lastClaim['event_id'], $observation->sourceEventId)
        ) {
            throw new \LogicException('paper_strategy_observation_source_unclaimed');
        }
        $payload = $observation->toArray();
        $checksum = hash('sha256', CanonicalJson::encode($payload));
        if (isset($this->observations[$position])) {
            if (!hash_equals($this->observations[$position], $checksum)) {
                throw new \LogicException('paper_strategy_observation_conflict');
            }

            return;
        }
        $this->observations[$position] = $checksum;
        $this->append('strategy_observed', $payload, $position, $observation->sourceEventId, null);
    }

    /** @param array<string, mixed> $payload */
    public function appendEffect(int $position, string $effectKey, array $payload): void
    {
        if ($this->killed) {
            throw new \LogicException('paper_execution_cell_killed');
        }
        if ($position < 0 || $position >= $this->next) {
            throw new \LogicException('paper_execution_effect_source_unclaimed');
        }
        $checksum = hash('sha256', CanonicalJson::encode($payload));
        if (isset($this->requested[$effectKey])) {
            if ($this->requested[$effectKey]['position'] !== $position
                || !hash_equals($this->requested[$effectKey]['checksum'], $checksum)
            ) {
                throw new \LogicException('paper_execution_effect_conflict');
            }

            return;
        }
        $this->requested[$effectKey] = ['position' => $position, 'checksum' => $checksum];
        $this->pending[$effectKey] = [
            'position' => $position,
            // Read back exactly as the former per-event store returned it from the database.
            'payload' => self::asStoredJson($payload),
            'provisional' => $this->provisionalOrdinal() + 1,
        ];
        $this->append('effect_requested', $payload, $position, null, $effectKey);
    }

    /** @return list<PaperPendingEffect> */
    public function pendingEffects(?int $position = null): array
    {
        $effects = [];
        foreach ($this->pending as $effectKey => $pending) {
            if ($position === null || $pending['position'] === $position) {
                $effects[] = new PaperPendingEffect(
                    sourcePosition: $pending['position'],
                    effectKey: $effectKey,
                    payload: $pending['payload'],
                    journalOrdinal: $pending['provisional'],
                );
            }
        }

        return $effects;
    }

    /**
     * The value the per-event store returned when it read a journal payload back: the
     * canonical JSON it inserted, parsed by PostgreSQL jsonb and decoded from payload::text.
     * jsonb keeps numbers as written (an integral float such as 30285.0 is stored as 30285
     * and decodes as an int) and returns object keys shorter-first, then in byte order.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function asStoredJson(array $payload): array
    {
        $decoded = json_decode(CanonicalJson::encode($payload), true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new \LogicException('paper_execution_checkpoint_corrupt');
        }

        /** @var array<string, mixed> $ordered */
        $ordered = self::jsonbKeyOrder($decoded);

        return $ordered;
    }

    private static function jsonbKeyOrder(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $value = array_map(self::jsonbKeyOrder(...), $value);
        if (!array_is_list($value)) {
            uksort($value, static fn (int|string $left, int|string $right): int =>
                \strlen((string) $left) <=> \strlen((string) $right) ?: strcmp((string) $left, (string) $right));
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    public function acknowledge(int $position, string $effectKey, array $payload, int $fakeEventCursor): void
    {
        if (($this->requested[$effectKey]['position'] ?? null) !== $position) {
            throw new \LogicException('paper_execution_effect_not_pending');
        }
        $journalPayload = [
            'acknowledgement' => $payload,
            'fake_event_cursor' => $fakeEventCursor,
        ];
        $checksum = hash('sha256', CanonicalJson::encode($journalPayload));
        if (isset($this->acknowledged[$effectKey])) {
            if (!hash_equals($this->acknowledged[$effectKey], $checksum)) {
                throw new \LogicException('paper_execution_effect_acknowledgement_conflict');
            }

            return;
        }
        $this->acknowledged[$effectKey] = $checksum;
        unset($this->pending[$effectKey]);
        $this->append('effect_acknowledged', $journalPayload, $position, null, $effectKey);
        $this->cursor = $fakeEventCursor;
    }

    /** @param array<string, mixed> $payload */
    public function recordEffectOutcome(int $position, string $effectKey, string $eventType, array $payload): void
    {
        if (($this->requested[$effectKey]['position'] ?? null) !== $position) {
            throw new \LogicException('paper_execution_effect_not_pending');
        }
        $this->append($eventType, $payload, $position, null, $effectKey);
    }

    public function recordMarketEffectMateriality(int $position, string $effectKey, bool $material): void
    {
        if (($this->requested[$effectKey]['position'] ?? null) !== $position) {
            throw new \LogicException('paper_execution_effect_not_pending');
        }
        if ($material) {
            $this->materialPositions[$position] = true;
        }
    }

    /**
     * @param \Closure(int): array{sha256: string, bytes: int, state_revision: int} $writeSnapshot
     *
     * @return array{journal_ordinal: int, journal_checksum: string, next_source_position: int, fake_event_cursor: int, snapshot_sha256: string, snapshot_bytes: int}
     */
    public function commit(\Closure $writeSnapshot): array
    {
        if ($this->pending !== []) {
            throw new \LogicException('paper_execution_effect_pending');
        }
        $rows = $this->materializedRows();
        $ordinal = $this->ordinal;
        $checksum = $this->checksum;
        $records = [];
        foreach ($rows as $row) {
            ++$ordinal;
            $canonicalPayload = CanonicalJson::encode($row['payload']);
            $payloadChecksum = hash('sha256', $canonicalPayload);
            $checksum = ($this->chain)(
                $checksum,
                $this->cell->id,
                $ordinal,
                $row['type'],
                $row['position'],
                $row['event_id'],
                $row['effect_key'],
                $row['payload'],
                $payloadChecksum,
            );
            $records[] = [$ordinal, $row['type'], $row['position'], $row['event_id'], $row['effect_key'], $canonicalPayload, $payloadChecksum];
        }

        ++$ordinal;
        $snapshot = $writeSnapshot($ordinal);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $snapshot['sha256']) !== 1 || $snapshot['bytes'] < 1) {
            throw new \LogicException('paper_fake_state_snapshot_invalid');
        }
        $snapshotPayload = [
            'schema_version' => self::SNAPSHOT_SCHEMA,
            'sha256' => $snapshot['sha256'],
            'bytes' => $snapshot['bytes'],
            'state_revision' => $snapshot['state_revision'],
            'next_source_position' => $this->next,
            'fake_event_cursor' => $this->cursor,
        ];
        $canonicalPayload = CanonicalJson::encode($snapshotPayload);
        $payloadChecksum = hash('sha256', $canonicalPayload);
        $checksum = ($this->chain)(
            $checksum,
            $this->cell->id,
            $ordinal,
            self::SNAPSHOT_EVENT_TYPE,
            null,
            null,
            null,
            $snapshotPayload,
            $payloadChecksum,
        );
        $records[] = [$ordinal, self::SNAPSHOT_EVENT_TYPE, null, null, null, $canonicalPayload, $payloadChecksum];

        foreach (array_chunk($records, self::INSERT_CHUNK_ROWS) as $chunk) {
            $values = [];
            $parameters = [];
            foreach ($chunk as $record) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?::jsonb, ?, NOW())';
                array_push($parameters, $this->cell->id, ...$record);
            }
            $this->connection->executeStatement(
                'INSERT INTO paper_execution_event (cell_id, journal_ordinal, event_type, source_position, source_event_id, effect_key, payload, payload_checksum, appended_at) VALUES '
                . implode(', ', $values),
                $parameters,
            );
        }
        $updated = $this->connection->executeStatement(
            'UPDATE paper_execution_checkpoint SET next_source_position = ?, journal_ordinal = ?, journal_checksum = ?, fake_event_cursor = ?, lock_version = ?, updated_at = NOW() WHERE cell_id = ? AND journal_ordinal = ? AND lock_version = ?',
            [$this->next, $ordinal, $checksum, $this->cursor, $ordinal, $this->cell->id, $this->ordinal, $this->ordinal],
        );
        if ($updated !== 1) {
            throw new \LogicException('paper_execution_checkpoint_conflict');
        }
        $this->connection->commit();

        $this->ordinal = $ordinal;
        $this->checksum = $checksum;
        $this->flushedNext = $this->next;
        $this->flushedCursor = $this->cursor;
        $this->entries = [];
        $this->materialPositions = [];
        $this->requested = [];
        $this->acknowledged = [];
        $this->observations = [];

        return [
            'journal_ordinal' => $ordinal,
            'journal_checksum' => $checksum,
            'next_source_position' => $this->next,
            'fake_event_cursor' => $this->cursor,
            'snapshot_sha256' => $snapshot['sha256'],
            'snapshot_bytes' => $snapshot['bytes'],
        ];
    }

    /** @return array{position: int, event_id: string, exchange_timestamp: string}|null */
    public function lastClaim(): ?array
    {
        return $this->lastClaim;
    }

    private function hasPendingAt(int $position): bool
    {
        foreach ($this->pending as $pending) {
            if ($pending['position'] === $position) {
                return true;
            }
        }

        return false;
    }

    private function provisionalOrdinal(): int
    {
        return $this->ordinal + \count($this->entries);
    }

    /** @param array<string, mixed> $payload */
    private function append(string $type, array $payload, ?int $position, ?string $eventId, ?string $effectKey): void
    {
        $this->entries[] = [
            'type' => $type,
            'position' => $position,
            'event_id' => $eventId,
            'effect_key' => $effectKey,
            'payload' => $payload,
        ];
    }

    /**
     * @return list<array{type: string, position: int|null, event_id: string|null, effect_key: string|null, payload: array<string, mixed>}>
     */
    private function materializedRows(): array
    {
        $byPosition = [];
        foreach ($this->entries as $entry) {
            if ($entry['position'] === null) {
                throw new \LogicException('paper_execution_batch_entry_invalid');
            }
            $byPosition[$entry['position']][] = $entry;
        }

        $rows = [];
        $run = null;
        for ($position = $this->flushedNext; $position < $this->next; ++$position) {
            $entries = $byPosition[$position] ?? throw new \LogicException('paper_execution_batch_entry_invalid');
            $pure = $this->pureSource($position, $entries);
            if ($pure === null) {
                if ($run !== null) {
                    $rows[] = $this->batchRow($run);
                    $run = null;
                }
                foreach ($entries as $entry) {
                    $rows[] = $entry;
                }

                continue;
            }
            $run ??= [
                'from' => $position,
                'sources' => hash_init('sha256'),
                'observations' => hash_init('sha256'),
                'runs' => [],
                'last' => null,
            ];
            hash_update($run['sources'], $position . ':' . $pure['event_id'] . ':' . $pure['payload_hash'] . "\n");
            hash_update($run['observations'], $position . ':' . ($pure['status'] ?? '') . ':' . ($pure['reason_code'] ?? '') . "\n");
            $lastRun = array_key_last($run['runs']);
            if ($lastRun !== null
                && $run['runs'][$lastRun][0] === $pure['status']
                && $run['runs'][$lastRun][1] === $pure['reason_code']
            ) {
                ++$run['runs'][$lastRun][2];
            } else {
                $run['runs'][] = [$pure['status'], $pure['reason_code'], 1];
            }
            $run['last'] = $pure + ['position' => $position];
        }
        if ($run !== null) {
            $rows[] = $this->batchRow($run);
        }

        return $rows;
    }

    /**
     * @param list<array{type: string, position: int|null, event_id: string|null, effect_key: string|null, payload: array<string, mixed>}> $entries
     *
     * @return array{event_id: string, payload_hash: string, exchange_timestamp: string, status: string|null, reason_code: string|null}|null
     */
    private function pureSource(int $position, array $entries): ?array
    {
        if (isset($this->materialPositions[$position])) {
            return null;
        }
        $types = array_column($entries, 'type');
        $withObservation = ['source_claimed', 'strategy_observed', 'effect_requested', 'effect_acknowledged'];
        $withoutObservation = ['source_claimed', 'effect_requested', 'effect_acknowledged'];
        if ($types !== $withObservation && $types !== $withoutObservation) {
            return null;
        }
        $claim = $entries[0]['payload'];
        $observation = $types === $withObservation ? $entries[1]['payload'] : null;
        $requested = $entries[\count($entries) - 2];
        $acknowledged = $entries[\count($entries) - 1];
        if (($requested['payload']['effect_type'] ?? null) !== 'market_event'
            || $requested['effect_key'] !== $acknowledged['effect_key']
            || ($acknowledged['payload']['acknowledgement']['effect_type'] ?? null) !== 'market_event'
            || ($acknowledged['payload']['acknowledgement']['event_types'] ?? null) !== []
            || !\is_string($claim['event_id'] ?? null)
            || !\is_string($claim['payload_hash'] ?? null)
            || !\is_string($claim['exchange_timestamp'] ?? null)
        ) {
            return null;
        }
        if ($observation !== null && !$this->isReconstructibleObservation($observation, $claim['event_id'])) {
            return null;
        }
        $status = $observation['status'] ?? null;
        $reason = $observation['reason_code'] ?? null;

        return [
            'event_id' => $claim['event_id'],
            'payload_hash' => $claim['payload_hash'],
            'exchange_timestamp' => $claim['exchange_timestamp'],
            'status' => \is_string($status) ? $status : null,
            'reason_code' => \is_string($reason) ? $reason : null,
        ];
    }

    /**
     * A folded observation keeps only (status, reason_code): it may be folded only when every
     * other field is exactly what the cell identity and the claimed source event determine,
     * so that the former row is rebuilt byte for byte. Anything else stays an exact row.
     *
     * @param array<string, mixed> $observation
     */
    private function isReconstructibleObservation(array $observation, string $sourceEventId): bool
    {
        $identity = $this->cell->modernIdentity;
        if ($identity === null
            || !\is_string($observation['status'] ?? null)
            || !\is_string($observation['reason_code'] ?? null)
        ) {
            return false;
        }
        $expected = [
            'schema_version' => 'paper-strategy-observation.v1',
            'status' => $observation['status'],
            'reason_code' => $observation['reason_code'],
            'source_event_id' => $sourceEventId,
            'mode_id' => $identity->modeId,
            'mode_version' => $identity->modeVersion,
            'setup_id' => $identity->setupId,
            'setup_version' => $identity->setupVersion,
            'side' => $identity->side,
            'config_hash' => $identity->configHash,
            'condition_catalog_hash' => $identity->conditionCatalogHash,
        ];

        return $observation === $expected;
    }

    /**
     * @param array{from: int, sources: \HashContext, observations: \HashContext, runs: list<array{0: string|null, 1: string|null, 2: int}>, last: array<string, mixed>|null} $run
     *
     * @return array{type: string, position: int|null, event_id: string|null, effect_key: string|null, payload: array<string, mixed>}
     */
    private function batchRow(array $run): array
    {
        $last = $run['last'] ?? throw new \LogicException('paper_execution_batch_entry_invalid');
        $to = (int) $last['position'] + 1;

        return [
            'type' => self::BATCH_EVENT_TYPE,
            'position' => $run['from'],
            'event_id' => (string) $last['event_id'],
            'effect_key' => null,
            'payload' => [
                'schema_version' => self::BATCH_SCHEMA,
                'from_position' => $run['from'],
                'to_position' => $to,
                'event_count' => $to - $run['from'],
                'sources_sha256' => hash_final($run['sources']),
                'observations_sha256' => hash_final($run['observations']),
                'observations' => $run['runs'],
                'last_source_event_id' => (string) $last['event_id'],
                'last_source_exchange_timestamp' => (string) $last['exchange_timestamp'],
                'fake_event_cursor' => $this->cursorBefore($run['from']),
            ],
        ];
    }

    private function cursorBefore(int $position): int
    {
        // Pure positions leave the cursor unchanged: it is the cursor of the latest
        // acknowledgement before the run, i.e. the batch start cursor or the last material ack.
        $cursor = null;
        foreach ($this->entries as $entry) {
            if ($entry['position'] !== null && $entry['position'] >= $position) {
                break;
            }
            if ($entry['type'] === 'effect_acknowledged') {
                $cursor = $entry['payload']['fake_event_cursor'] ?? null;
            }
        }

        return \is_int($cursor) ? $cursor : $this->flushedCursor;
    }
}
