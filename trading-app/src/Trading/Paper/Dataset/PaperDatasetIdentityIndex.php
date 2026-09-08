<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

/**
 * Exact, process-local identity lookup rebuilt from the durable event log.
 *
 * The empty SQLite filename creates a temporary disk-backed database. It is a
 * bounded-memory cache only: events.ndjson and its append intent remain the
 * durability authority across process loss.
 */
final class PaperDatasetIdentityIndex
{
    private \PDO $database;
    private \PDOStatement $insert;
    private \PDOStatement $select;
    private \PDOStatement $count;
    private bool $candidateOpen = false;

    public function __construct()
    {
        try {
            $this->database = new \PDO('sqlite:', null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $this->database->exec('PRAGMA journal_mode = MEMORY');
            $this->database->exec('PRAGMA synchronous = OFF');
            $this->database->exec('PRAGMA temp_store = FILE');
            $this->database->exec('PRAGMA cache_size = -2048');
            $this->database->exec(
                'CREATE TABLE identities ('
                . 'event_id BLOB PRIMARY KEY, '
                . 'payload_hash BLOB NOT NULL, '
                . 'event_hash BLOB NOT NULL'
                . ') WITHOUT ROWID',
            );
            $this->insert = $this->database->prepare(
                'INSERT INTO identities (event_id, payload_hash, event_hash) VALUES (:event_id, :payload_hash, :event_hash)',
            );
            $this->select = $this->database->prepare(
                'SELECT payload_hash, event_hash FROM identities WHERE event_id = :event_id',
            );
            $this->count = $this->database->prepare('SELECT COUNT(*) FROM identities');
        } catch (\Throwable $failure) {
            throw new \RuntimeException('paper_dataset_identity_index_unavailable', 0, $failure);
        }
    }

    /** @return array{payload_hash: string, event_hash: string}|null */
    public function find(string $eventId): ?array
    {
        $eventIdBinary = $this->decodeHash($eventId);
        try {
            $this->select->bindValue(':event_id', $eventIdBinary, \PDO::PARAM_LOB);
            $this->select->execute();
            $row = $this->select->fetch(\PDO::FETCH_ASSOC);
            $this->select->closeCursor();
        } catch (\Throwable $failure) {
            throw new \RuntimeException('paper_dataset_identity_index_unavailable', 0, $failure);
        }
        if ($row === false) {
            return null;
        }
        if (!isset($row['payload_hash'], $row['event_hash'])
            || !\is_string($row['payload_hash'])
            || !\is_string($row['event_hash'])
        ) {
            throw new \RuntimeException('paper_dataset_identity_index_unavailable');
        }

        return [
            'payload_hash' => bin2hex($row['payload_hash']),
            'event_hash' => bin2hex($row['event_hash']),
        ];
    }

    public function add(string $eventId, string $payloadHash, string $eventHash): void
    {
        try {
            $this->insert->bindValue(':event_id', $this->decodeHash($eventId), \PDO::PARAM_LOB);
            $this->insert->bindValue(':payload_hash', $this->decodeHash($payloadHash), \PDO::PARAM_LOB);
            $this->insert->bindValue(':event_hash', $this->decodeHash($eventHash), \PDO::PARAM_LOB);
            $this->insert->execute();
            $this->insert->closeCursor();
        } catch (\PDOException $failure) {
            if ((string) $failure->getCode() === '23000') {
                throw new \RuntimeException('paper_dataset_identity_index_conflict', 0, $failure);
            }

            throw new \RuntimeException('paper_dataset_identity_index_unavailable', 0, $failure);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('paper_dataset_identity_index_unavailable', 0, $failure);
        }
    }

    /**
     * @param list<array{event_id: string, payload_hash: string, event_hash: string}> $identities
     */
    public function addBatch(array $identities): void
    {
        $this->beginCandidate();
        try {
            foreach ($identities as $identity) {
                $this->add(
                    $identity['event_id'],
                    $identity['payload_hash'],
                    $identity['event_hash'],
                );
            }
            $this->commitCandidate();
        } catch (\Throwable $failure) {
            $this->rollbackCandidate();

            throw $failure;
        }
    }

    public function beginCandidate(): void
    {
        if ($this->candidateOpen) {
            throw new \LogicException('paper_dataset_identity_index_candidate_open');
        }
        try {
            $this->database->exec('SAVEPOINT paper_identity_candidate');
            $this->candidateOpen = true;
        } catch (\Throwable $failure) {
            throw new \RuntimeException('paper_dataset_identity_index_unavailable', 0, $failure);
        }
    }

    public function commitCandidate(): void
    {
        if (!$this->candidateOpen) {
            throw new \LogicException('paper_dataset_identity_index_candidate_missing');
        }
        try {
            $this->database->exec('RELEASE SAVEPOINT paper_identity_candidate');
            $this->candidateOpen = false;
        } catch (\Throwable $failure) {
            throw new \RuntimeException('paper_dataset_identity_index_unavailable', 0, $failure);
        }
    }

    public function rollbackCandidate(): void
    {
        if (!$this->candidateOpen) {
            return;
        }
        try {
            $this->database->exec('ROLLBACK TO SAVEPOINT paper_identity_candidate');
            $this->database->exec('RELEASE SAVEPOINT paper_identity_candidate');
            $this->candidateOpen = false;
        } catch (\Throwable $failure) {
            throw new \RuntimeException('paper_dataset_identity_index_unavailable', 0, $failure);
        }
    }

    public function count(): int
    {
        try {
            $this->count->execute();
            $value = $this->count->fetchColumn();
            $this->count->closeCursor();
        } catch (\Throwable $failure) {
            throw new \RuntimeException('paper_dataset_identity_index_unavailable', 0, $failure);
        }
        if (!\is_int($value) && !(\is_string($value) && ctype_digit($value))) {
            throw new \RuntimeException('paper_dataset_identity_index_unavailable');
        }

        return (int) $value;
    }

    private function decodeHash(string $hash): string
    {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $hash) !== 1) {
            throw new \InvalidArgumentException('paper_dataset_identity_index_hash_invalid');
        }
        $decoded = hex2bin($hash);
        if ($decoded === false) {
            throw new \InvalidArgumentException('paper_dataset_identity_index_hash_invalid');
        }

        return $decoded;
    }
}
