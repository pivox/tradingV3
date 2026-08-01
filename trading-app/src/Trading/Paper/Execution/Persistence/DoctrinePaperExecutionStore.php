<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Persistence;

use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshot;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Profile\PaperProfileRegistry;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

final class DoctrinePaperExecutionStore implements PaperExecutionStoreInterface
{
    private const EMPTY_JOURNAL_CHECKSUM = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function registerSnapshot(PaperConfigurationSnapshot $snapshot): void
    {
        $this->atomic(function () use ($snapshot): void {
            $existing = $this->connection->fetchAssociative('SELECT schema_version, canonical_json::text AS canonical_json, content_checksum FROM paper_configuration_snapshot WHERE id = ?', [$snapshot->id]);
            if ($existing !== false) {
                $storedCanonical = $this->canonicalizeStoredJson($existing['canonical_json'] ?? null);
                if ((int) $existing['schema_version'] !== $snapshot->schemaVersion
                    || !hash_equals($snapshot->canonicalJson, $storedCanonical)
                    || !hash_equals(hash('sha256', $snapshot->canonicalJson), (string) $existing['content_checksum'])
                ) {
                    throw new \LogicException('paper_configuration_snapshot_conflict');
                }

                return;
            }

            try {
                $this->connection->executeStatement(
                    'INSERT INTO paper_configuration_snapshot (id, schema_version, canonical_json, content_checksum, created_at) VALUES (?, ?, ?::jsonb, ?, NOW())',
                    [$snapshot->id, $snapshot->schemaVersion, $snapshot->canonicalJson, hash('sha256', $snapshot->canonicalJson)],
                );
            } catch (UniqueConstraintViolationException) {
                throw new \LogicException('paper_configuration_snapshot_conflict');
            }
        });
    }

    public function registerCell(PaperExecutionCell $cell, PaperProfileEligibility $eligibility): void
    {
        if ((new PaperProfileRegistry())->require($cell->strategyProfile) !== $eligibility) {
            throw new \LogicException('paper_execution_cell_eligibility_conflict');
        }

        $this->atomic(function () use ($cell, $eligibility): void {
            $existing = $this->connection->fetchAssociative('SELECT * FROM paper_execution_cell WHERE id = ?', [$cell->id]);
            if ($existing !== false) {
                $expected = [
                    'network' => $cell->network->value,
                    'market_data_venue' => $cell->marketDataVenue->value,
                    'configuration_snapshot_id' => $cell->configurationSnapshotId,
                    'strategy_profile' => $cell->strategyProfile,
                    'run_id' => $cell->runId,
                    'account_namespace' => $cell->accountNamespace,
                    'eligibility' => $eligibility->value,
                ];
                foreach ($expected as $key => $value) {
                    if (($existing[$key] ?? null) !== $value) {
                        throw new \LogicException('paper_execution_cell_identity_conflict');
                    }
                }

                return;
            }

            try {
                $this->connection->executeStatement(<<<'SQL'
INSERT INTO paper_execution_cell
    (id, network, market_data_venue, configuration_snapshot_id, strategy_profile, run_id, account_namespace, eligibility, terminal_state, created_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
SQL, [
                    $cell->id,
                    $cell->network->value,
                    $cell->marketDataVenue->value,
                    $cell->configurationSnapshotId,
                    $cell->strategyProfile,
                    $cell->runId,
                    $cell->accountNamespace,
                    $eligibility->value,
                ]);
                $this->connection->executeStatement(<<<'SQL'
INSERT INTO paper_execution_checkpoint
    (cell_id, next_source_position, journal_ordinal, journal_checksum, fake_event_cursor, killed, lock_version, updated_at)
VALUES (?, 0, 0, ?, 0, FALSE, 0, NOW())
SQL, [$cell->id, self::EMPTY_JOURNAL_CHECKSUM]);
            } catch (UniqueConstraintViolationException) {
                throw new \LogicException('paper_execution_cell_identity_conflict');
            }
        });
    }

    public function transactional(callable $operation): mixed
    {
        return $this->connection->transactional(static fn (): mixed => $operation());
    }

    public function claimSource(PaperExecutionCell $cell, int $position, PaperMarketEvent $event): PaperSourceClaim
    {
        if ($position < 0) {
            throw new \InvalidArgumentException('paper_execution_source_position_invalid');
        }

        return $this->atomic(function () use ($cell, $position, $event): PaperSourceClaim {
            $checkpoint = $this->lockCheckpoint($cell);
            $this->verifyCheckpoint($checkpoint);
            $expected = (int) $checkpoint['next_source_position'];

            if ($position < $expected) {
                $existing = $this->connection->fetchAssociative("SELECT journal_ordinal, source_event_id, payload::text AS payload FROM paper_execution_event WHERE cell_id = ? AND source_position = ? AND event_type = 'source_claimed' ORDER BY journal_ordinal LIMIT 1", [$cell->id, $position]);
                if ($existing === false) {
                    throw new \LogicException('paper_execution_source_out_of_order');
                }
                $stored = $this->decodeJsonMap($existing['payload'] ?? null);
                if (($existing['source_event_id'] ?? null) === $event->eventId
                    && ($stored['payload_hash'] ?? null) !== $event->payloadHash
                ) {
                    throw new \LogicException('market_event_identity_conflict');
                }
                if (($existing['source_event_id'] ?? null) !== $event->eventId
                    || ($stored['payload_hash'] ?? null) !== $event->payloadHash
                ) {
                    throw new \LogicException('paper_execution_source_out_of_order');
                }

                return new PaperSourceClaim(PaperSourceClaim::REPLAYED, $position, (int) $existing['journal_ordinal']);
            }

            if ($position > $expected) {
                throw new \LogicException('paper_execution_source_gap');
            }
            if ($this->hasPendingEffects($cell->id)) {
                throw new \LogicException('paper_execution_effect_pending');
            }
            if ($this->databaseBoolean($checkpoint['killed'] ?? false)) {
                throw new \LogicException('paper_execution_cell_killed');
            }

            $ordinal = $this->appendJournal($checkpoint, 'source_claimed', $event->toArray(), $position, $event->eventId, null);
            $this->connection->executeStatement('UPDATE paper_execution_checkpoint SET next_source_position = ?, updated_at = NOW() WHERE cell_id = ?', [$position + 1, $cell->id]);

            return new PaperSourceClaim(PaperSourceClaim::ACCEPTED, $position, $ordinal);
        });
    }

    public function appendEffect(PaperExecutionCell $cell, int $position, string $effectKey, array $payload): void
    {
        $this->assertEffectKey($effectKey);
        $this->atomic(function () use ($cell, $position, $effectKey, $payload): void {
            $checkpoint = $this->lockCheckpoint($cell);
            $this->verifyCheckpoint($checkpoint);
            if ($this->databaseBoolean($checkpoint['killed'] ?? false)) {
                throw new \LogicException('paper_execution_cell_killed');
            }
            if ($position < 0 || $position >= (int) $checkpoint['next_source_position']) {
                throw new \LogicException('paper_execution_effect_source_unclaimed');
            }

            $canonicalPayload = CanonicalJson::encode($payload);
            $existing = $this->connection->fetchAssociative("SELECT source_position, payload_checksum FROM paper_execution_event WHERE cell_id = ? AND effect_key = ? AND event_type = 'effect_requested'", [$cell->id, $effectKey]);
            if ($existing !== false) {
                if ((int) $existing['source_position'] !== $position
                    || !hash_equals(hash('sha256', $canonicalPayload), (string) $existing['payload_checksum'])
                ) {
                    throw new \LogicException('paper_execution_effect_conflict');
                }

                return;
            }

            $this->appendJournal($checkpoint, 'effect_requested', $payload, $position, null, $effectKey);
        });
    }

    public function pendingEffects(PaperExecutionCell $cell): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT requested.source_position, requested.effect_key, requested.payload::text AS payload, requested.journal_ordinal
FROM paper_execution_event requested
WHERE requested.cell_id = ?
  AND requested.event_type = 'effect_requested'
  AND NOT EXISTS (
      SELECT 1 FROM paper_execution_event acknowledged
      WHERE acknowledged.cell_id = requested.cell_id
        AND acknowledged.effect_key = requested.effect_key
        AND acknowledged.event_type = 'effect_acknowledged'
  )
ORDER BY requested.journal_ordinal
SQL, [$cell->id]);

        return array_map(fn (array $row): PaperPendingEffect => new PaperPendingEffect(
            sourcePosition: (int) $row['source_position'],
            effectKey: (string) $row['effect_key'],
            payload: $this->decodeJsonMap($row['payload'] ?? null),
            journalOrdinal: (int) $row['journal_ordinal'],
        ), $rows);
    }

    public function acknowledge(PaperExecutionCell $cell, int $position, string $effectKey, array $payload, int $fakeEventCursor): void
    {
        $this->assertEffectKey($effectKey);
        if ($fakeEventCursor < 0) {
            throw new \InvalidArgumentException('paper_execution_fake_event_cursor_invalid');
        }

        $this->atomic(function () use ($cell, $position, $effectKey, $payload, $fakeEventCursor): void {
            $checkpoint = $this->lockCheckpoint($cell);
            $this->verifyCheckpoint($checkpoint);
            $requested = $this->connection->fetchAssociative("SELECT source_position FROM paper_execution_event WHERE cell_id = ? AND effect_key = ? AND event_type = 'effect_requested'", [$cell->id, $effectKey]);
            if ($requested === false || (int) $requested['source_position'] !== $position) {
                throw new \LogicException('paper_execution_effect_not_pending');
            }
            $existing = $this->connection->fetchAssociative("SELECT payload_checksum FROM paper_execution_event WHERE cell_id = ? AND effect_key = ? AND event_type = 'effect_acknowledged'", [$cell->id, $effectKey]);
            $payloadChecksum = hash('sha256', CanonicalJson::encode($payload));
            if ($existing !== false) {
                if (!hash_equals($payloadChecksum, (string) $existing['payload_checksum'])) {
                    throw new \LogicException('paper_execution_effect_acknowledgement_conflict');
                }

                return;
            }

            $this->appendJournal($checkpoint, 'effect_acknowledged', $payload, $position, null, $effectKey);
            $this->connection->executeStatement('UPDATE paper_execution_checkpoint SET fake_event_cursor = ?, updated_at = NOW() WHERE cell_id = ?', [$fakeEventCursor, $cell->id]);
        });
    }

    public function checkpoint(PaperExecutionCell $cell): PaperExecutionCheckpoint
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM paper_execution_checkpoint WHERE cell_id = ?', [$cell->id]);
        if ($row === false) {
            throw new \LogicException('paper_execution_cell_unknown');
        }
        $this->verifyCheckpoint($row);

        return $this->checkpointFromRow($row);
    }

    public function kill(PaperExecutionCell $cell): void
    {
        $this->changeKillState($cell, true, 'cell_killed');
    }

    public function resume(PaperExecutionCell $cell): void
    {
        $this->changeKillState($cell, false, 'cell_resumed');
    }

    private function changeKillState(PaperExecutionCell $cell, bool $killed, string $eventType): void
    {
        $this->atomic(function () use ($cell, $killed, $eventType): void {
            $checkpoint = $this->lockCheckpoint($cell);
            $this->verifyCheckpoint($checkpoint);
            if ($this->databaseBoolean($checkpoint['killed'] ?? false) === $killed) {
                return;
            }
            $this->appendJournal($checkpoint, $eventType, ['killed' => $killed], null, null, null);
            $this->connection->executeStatement(
                'UPDATE paper_execution_checkpoint SET killed = ?, updated_at = NOW() WHERE cell_id = ?',
                [$killed, $cell->id],
                [ParameterType::BOOLEAN, ParameterType::STRING],
            );
        });
    }

    /** @return array<string, mixed> */
    private function lockCheckpoint(PaperExecutionCell $cell): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM paper_execution_checkpoint WHERE cell_id = ? FOR UPDATE', [$cell->id]);
        if ($row === false) {
            throw new \LogicException('paper_execution_cell_unknown');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $checkpoint
     * @param array<string, mixed> $payload
     */
    private function appendJournal(
        array &$checkpoint,
        string $eventType,
        array $payload,
        ?int $sourcePosition,
        ?string $sourceEventId,
        ?string $effectKey,
    ): int {
        $ordinal = (int) $checkpoint['journal_ordinal'] + 1;
        $canonicalPayload = CanonicalJson::encode($payload);
        $payloadChecksum = hash('sha256', $canonicalPayload);
        $journalChecksum = $this->nextJournalChecksum(
            (string) $checkpoint['journal_checksum'],
            (string) $checkpoint['cell_id'],
            $ordinal,
            $eventType,
            $sourcePosition,
            $sourceEventId,
            $effectKey,
            $payload,
            $payloadChecksum,
        );

        $this->connection->executeStatement(<<<'SQL'
INSERT INTO paper_execution_event
    (cell_id, journal_ordinal, event_type, source_position, source_event_id, effect_key, payload, payload_checksum, appended_at)
VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, NOW())
SQL, [$checkpoint['cell_id'], $ordinal, $eventType, $sourcePosition, $sourceEventId, $effectKey, $canonicalPayload, $payloadChecksum]);
        $this->connection->executeStatement('UPDATE paper_execution_checkpoint SET journal_ordinal = ?, journal_checksum = ?, lock_version = lock_version + 1, updated_at = NOW() WHERE cell_id = ?', [$ordinal, $journalChecksum, $checkpoint['cell_id']]);
        $checkpoint['journal_ordinal'] = $ordinal;
        $checkpoint['journal_checksum'] = $journalChecksum;
        $checkpoint['lock_version'] = (int) $checkpoint['lock_version'] + 1;

        return $ordinal;
    }

    /** @param array<string, mixed> $checkpoint */
    private function verifyCheckpoint(array $checkpoint): void
    {
        $checksum = self::EMPTY_JOURNAL_CHECKSUM;
        $expectedOrdinal = 0;
        $rows = $this->connection->fetchAllAssociative('SELECT cell_id, journal_ordinal, event_type, source_position, source_event_id, effect_key, payload::text AS payload, payload_checksum FROM paper_execution_event WHERE cell_id = ? ORDER BY journal_ordinal', [$checkpoint['cell_id']]);
        foreach ($rows as $row) {
            ++$expectedOrdinal;
            if ((int) $row['journal_ordinal'] !== $expectedOrdinal) {
                throw new \LogicException('paper_execution_checkpoint_corrupt');
            }
            $payload = $this->decodeJsonMap($row['payload'] ?? null);
            $payloadChecksum = hash('sha256', CanonicalJson::encode($payload));
            if (!hash_equals($payloadChecksum, (string) $row['payload_checksum'])) {
                throw new \LogicException('paper_execution_checkpoint_corrupt');
            }
            $checksum = $this->nextJournalChecksum(
                $checksum,
                (string) $row['cell_id'],
                $expectedOrdinal,
                (string) $row['event_type'],
                $row['source_position'] === null ? null : (int) $row['source_position'],
                $row['source_event_id'] === null ? null : (string) $row['source_event_id'],
                $row['effect_key'] === null ? null : (string) $row['effect_key'],
                $payload,
                $payloadChecksum,
            );
        }

        if ((int) $checkpoint['journal_ordinal'] !== $expectedOrdinal
            || !hash_equals($checksum, (string) $checkpoint['journal_checksum'])
            || (int) $checkpoint['lock_version'] !== $expectedOrdinal
        ) {
            throw new \LogicException('paper_execution_checkpoint_corrupt');
        }
    }

    /** @param array<string, mixed> $payload */
    private function nextJournalChecksum(
        string $previousChecksum,
        string $cellId,
        int $ordinal,
        string $eventType,
        ?int $sourcePosition,
        ?string $sourceEventId,
        ?string $effectKey,
        array $payload,
        string $payloadChecksum,
    ): string {
        return hash('sha256', $previousChecksum . CanonicalJson::encode([
            'cell_id' => $cellId,
            'journal_ordinal' => $ordinal,
            'event_type' => $eventType,
            'source_position' => $sourcePosition,
            'source_event_id' => $sourceEventId,
            'effect_key' => $effectKey,
            'payload' => $payload,
            'payload_checksum' => $payloadChecksum,
        ]));
    }

    /** @param array<string, mixed> $row */
    private function checkpointFromRow(array $row): PaperExecutionCheckpoint
    {
        return new PaperExecutionCheckpoint(
            cellId: (string) $row['cell_id'],
            nextSourcePosition: (int) $row['next_source_position'],
            journalOrdinal: (int) $row['journal_ordinal'],
            journalChecksum: (string) $row['journal_checksum'],
            fakeEventCursor: (int) $row['fake_event_cursor'],
            killed: $this->databaseBoolean($row['killed'] ?? false),
            lockVersion: (int) $row['lock_version'],
        );
    }

    /** @return array<string, mixed> */
    private function decodeJsonMap(mixed $json): array
    {
        if (!is_string($json)) {
            throw new \LogicException('paper_execution_checkpoint_corrupt');
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \LogicException('paper_execution_checkpoint_corrupt');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \LogicException('paper_execution_checkpoint_corrupt');
        }

        return $decoded;
    }

    private function canonicalizeStoredJson(mixed $json): string
    {
        return CanonicalJson::encode($this->decodeJsonMap($json));
    }

    private function hasPendingEffects(string $cellId): bool
    {
        return (bool) $this->connection->fetchOne(<<<'SQL'
SELECT EXISTS (
    SELECT 1 FROM paper_execution_event requested
    WHERE requested.cell_id = ? AND requested.event_type = 'effect_requested'
      AND NOT EXISTS (
          SELECT 1 FROM paper_execution_event acknowledged
          WHERE acknowledged.cell_id = requested.cell_id
            AND acknowledged.effect_key = requested.effect_key
            AND acknowledged.event_type = 'effect_acknowledged'
      )
)
SQL, [$cellId]);
    }

    private function assertEffectKey(string $effectKey): void
    {
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $effectKey) !== 1) {
            throw new \InvalidArgumentException('paper_execution_effect_key_invalid');
        }
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function atomic(callable $operation): mixed
    {
        if ($this->connection->getTransactionNestingLevel() > 0) {
            return $operation();
        }

        return $this->transactional($operation);
    }
}
