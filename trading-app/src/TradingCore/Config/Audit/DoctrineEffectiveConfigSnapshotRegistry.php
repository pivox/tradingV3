<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class DoctrineEffectiveConfigSnapshotRegistry implements EffectiveConfigSnapshotRegistryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function register(EffectiveConfigViewerDocument $document): void
    {
        $this->connection->transactional(function () use ($document): void {
            $existing = $this->connection->fetchAssociative(
                'SELECT *, redacted_snapshot::text AS redacted_snapshot_json FROM effective_trading_config_snapshot WHERE snapshot_hash = ?',
                [$document->snapshotHash()],
            );
            if ($existing !== false) {
                $this->assertExactReplay($existing, $document);

                return;
            }

            $payload = $document->payload;
            $request = $this->requiredMap($payload, 'request');
            $config = $this->requiredMap($payload, 'config');
            try {
                $this->connection->executeStatement(<<<'SQL'
INSERT INTO effective_trading_config_snapshot (
    snapshot_hash, config_hash, condition_catalog_hash, schema_version, resolver_version,
    mode_id, mode_version, setup_id, setup_version, exchange, environment, side,
    execution_capability, validation_status, redacted_snapshot, redacted_content_checksum, created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, NOW())
SQL, [
                    $document->snapshotHash(),
                    $document->configHash(),
                    $this->nullableString($payload, 'condition_catalog_hash'),
                    $this->requiredString($config, 'schema_version'),
                    $this->requiredString($payload, 'resolver_version'),
                    $this->requiredString($request, 'mode_id'),
                    $this->requiredString($request, 'mode_version'),
                    $this->requiredString($request, 'setup_id'),
                    $this->requiredString($request, 'setup_version'),
                    $this->requiredString($request, 'exchange'),
                    $this->requiredString($request, 'environment'),
                    $this->requiredString($request, 'side'),
                    $this->nullableString($request, 'execution_capability'),
                    $this->requiredString($payload, 'validation_status'),
                    $document->canonicalJson(),
                    $document->redactedContentChecksum(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new \LogicException('effective_config_snapshot_conflict');
            }
        });
    }

    public function find(string $snapshotHash): ?EffectiveConfigSnapshotRecord
    {
        $row = $this->connection->fetchAssociative(
            'SELECT redacted_snapshot::text AS redacted_snapshot_json, redacted_content_checksum, created_at FROM effective_trading_config_snapshot WHERE snapshot_hash = ?',
            [$snapshotHash],
        );

        return $row === false ? null : $this->record($row);
    }

    public function findByConfigHash(string $configHash): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT redacted_snapshot::text AS redacted_snapshot_json, redacted_content_checksum, created_at FROM effective_trading_config_snapshot WHERE config_hash = ? ORDER BY created_at, snapshot_hash',
            [$configHash],
        );

        return array_map($this->record(...), $rows);
    }

    /** @param array<string,mixed> $row */
    private function assertExactReplay(array $row, EffectiveConfigViewerDocument $document): void
    {
        $payload = $document->payload;
        $request = $this->requiredMap($payload, 'request');
        $config = $this->requiredMap($payload, 'config');
        $expected = [
            'snapshot_hash' => $document->snapshotHash(),
            'config_hash' => $document->configHash(),
            'condition_catalog_hash' => $this->nullableString($payload, 'condition_catalog_hash'),
            'schema_version' => $this->requiredString($config, 'schema_version'),
            'resolver_version' => $this->requiredString($payload, 'resolver_version'),
            'mode_id' => $this->requiredString($request, 'mode_id'),
            'mode_version' => $this->requiredString($request, 'mode_version'),
            'setup_id' => $this->requiredString($request, 'setup_id'),
            'setup_version' => $this->requiredString($request, 'setup_version'),
            'exchange' => $this->requiredString($request, 'exchange'),
            'environment' => $this->requiredString($request, 'environment'),
            'side' => $this->requiredString($request, 'side'),
            'execution_capability' => $this->nullableString($request, 'execution_capability'),
            'validation_status' => $this->requiredString($payload, 'validation_status'),
            'redacted_content_checksum' => $document->redactedContentChecksum(),
        ];
        foreach ($expected as $field => $value) {
            if (($row[$field] ?? null) !== $value) {
                throw new \LogicException('effective_config_snapshot_conflict');
            }
        }
        $stored = $this->decodeDocument($row['redacted_snapshot_json'] ?? null);
        if (!hash_equals($document->canonicalJson(), EffectiveConfigCanonicalJson::encode($stored))) {
            throw new \LogicException('effective_config_snapshot_conflict');
        }
    }

    /** @param array<string,mixed> $row */
    private function record(array $row): EffectiveConfigSnapshotRecord
    {
        $document = $this->decodeDocument($row['redacted_snapshot_json'] ?? null);
        $canonical = EffectiveConfigCanonicalJson::encode($document);
        $checksum = $row['redacted_content_checksum'] ?? null;
        if (!is_string($checksum) || !hash_equals(hash('sha256', $canonical), $checksum)) {
            throw new \LogicException('effective_config_snapshot_checksum_mismatch');
        }
        $createdAt = $row['created_at'] ?? null;
        if (!is_string($createdAt)) {
            throw new \LogicException('effective_config_snapshot_invalid');
        }

        return new EffectiveConfigSnapshotRecord($document, new \DateTimeImmutable($createdAt));
    }

    /** @return array<string,mixed> */
    private function decodeDocument(mixed $json): array
    {
        if (!is_string($json)) {
            throw new \LogicException('effective_config_snapshot_invalid');
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \LogicException('effective_config_snapshot_invalid');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \LogicException('effective_config_snapshot_invalid');
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $source
     *
     * @return array<string,mixed>
     */
    private function requiredMap(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new \LogicException('effective_config_document_invalid');
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private function requiredString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \LogicException('effective_config_document_invalid');
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private function nullableString(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \LogicException('effective_config_document_invalid');
        }

        return $value;
    }
}
